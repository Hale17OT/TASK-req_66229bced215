<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthenticationException;
use app\exception\BusinessException;
use app\model\User;
use app\service\audit\AuditService;
use app\service\auth\LockoutTracker;
use app\service\auth\PasswordPolicy;
use app\service\auth\PermissionResolver;
use app\service\auth\SessionService;
use think\facade\Db;
use think\facade\Session;
use think\Response;

class AuthController extends BaseController
{
    public function login(): Response
    {
        $data = $this->request->only(['username', 'password'], 'post');
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new BusinessException('Username and password are required', 40000, 422);
        }

        $tracker = LockoutTracker::fromConfig();
        $audit = app()->make(AuditService::class);
        $user = User::where('username', $username)->find();

        if (!$user) {
            $tracker->recordAttempt($username, $this->request->ip(), false, 'unknown_user');
            $audit->record('auth.login.failure', 'user', 'unknown:' . $username, null, null, ['reason' => 'unknown_user'], false);
            throw new AuthenticationException('Invalid credentials');
        }
        // Auto-release lock if cooldown elapsed
        $tracker->maybeReleaseLock($user);
        if ($user->status === 'disabled') {
            $tracker->recordAttempt($username, $this->request->ip(), false, 'disabled');
            $audit->record('auth.login.failure', 'user', $user->id, null, null, ['reason' => 'disabled'], false);
            throw new AuthenticationException('Account disabled');
        }
        if ($user->isLocked()) {
            $tracker->recordAttempt($username, $this->request->ip(), false, 'locked');
            $audit->record('auth.login.failure', 'user', $user->id, null, null, ['reason' => 'locked'], false);
            throw new AuthenticationException('Account is locked. Try again later.');
        }

        $policy = PasswordPolicy::fromConfig();
        if (!$policy->verify($password, (string)$user->password_hash)) {
            $user->failed_login_count = (int)$user->failed_login_count + 1;
            $user->save();
            $tracker->recordAttempt($username, $this->request->ip(), false, 'bad_password');
            if ($tracker->shouldLock($username)) {
                $tracker->lockUser($user);
                $audit->record('auth.account.locked', 'user', $user->id, null, null, ['reason' => 'failed_login_threshold']);
                throw new AuthenticationException('Account is now locked. Try again later.');
            }
            $audit->record('auth.login.failure', 'user', $user->id, null, null, ['reason' => 'bad_password'], false);
            throw new AuthenticationException('Invalid credentials');
        }

        // Successful login
        $user->failed_login_count = 0;
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $this->request->ip();
        // Mark password expiry inline if applicable
        if ($policy->isExpired($user)) $user->status = 'password_expired';
        $user->save();

        Session::regenerate();
        Session::set('user_id', (int)$user->id);
        // Bootstrap the CSRF token now so the frontend can immediately issue
        // authenticated POSTs (e.g. password change) after login.
        $csrf = bin2hex(random_bytes(32));
        Session::set('csrf_token', $csrf);
        $sessionsSvc = SessionService::fromConfig();
        $sessionsSvc->start($user, (string)Session::getId(), $this->request);
        $tracker->recordAttempt($username, $this->request->ip(), true);
        $audit->record('auth.login.success', 'user', $user->id, null, null, [
            'ip' => $this->request->ip(),
            'ua' => substr((string)$this->request->header('user-agent', ''), 0, 200),
        ]);

        return json_response(0, 'ok', $this->meSnapshot($user))
            ->cookie('studio_csrf', $csrf, ['expire' => 0, 'httponly' => false, 'samesite' => 'Lax']);
    }

    public function logout(): Response
    {
        $userId = $this->request->userId;
        $sessionsSvc = SessionService::fromConfig();
        $sid = (string)Session::getId();
        $row = $sessionsSvc->findValid($sid);
        if ($row) $sessionsSvc->revoke($row, 'logout', $userId);
        Session::destroy();
        if ($userId) {
            app()->make(AuditService::class)->record('auth.logout', 'user', $userId);
        }
        return json_response(0, 'ok');
    }

    public function me(): Response
    {
        if (!$this->request->user) throw new AuthenticationException();
        return json_response(0, 'ok', $this->meSnapshot($this->request->user));
    }

    public function changePassword(): Response
    {
        $data = $this->request->only(['current_password', 'new_password'], 'post');
        $current = (string)($data['current_password'] ?? '');
        $new = (string)($data['new_password'] ?? '');
        $user = $this->request->user;
        if (!$user) throw new AuthenticationException();

        $policy = PasswordPolicy::fromConfig();
        if (!$policy->verify($current, (string)$user->password_hash)) {
            throw new BusinessException('Current password is incorrect', 40022, 422,
                ['current_password' => ['Current password is incorrect.']]);
        }
        $policy->assertAcceptable((int)$user->id, $new);

        Db::transaction(function () use ($policy, $user, $new) {
            $policy->setForUser($user, $new);
        });

        // Revoke all OTHER sessions on password change for safety
        $sessionsSvc = SessionService::fromConfig();
        $sid = (string)Session::getId();
        foreach ($sessionsSvc->activeForUser((int)$user->id) as $row) {
            if (hash('sha256', $sid) !== $row['session_id']) {
                $svcRow = \app\model\UserSession::find($row['id']);
                if ($svcRow) $sessionsSvc->revoke($svcRow, 'password_change', (int)$user->id);
            }
        }
        app()->make(AuditService::class)->record('auth.password.changed', 'user', $user->id, null, null, ['by' => 'self']);
        return json_response(0, 'ok');
    }

    private function meSnapshot(User $user): array
    {
        $resolver = app()->make(PermissionResolver::class);
        $perms = $resolver->permissionsFor((int)$user->id);
        $set = array_flip($perms);
        $has = static fn (string $p): bool => isset($set[$p]);
        $hasAny = static fn (array $ps): bool => (bool)array_intersect($ps, $perms);

        // Compact capability map consumed by the Layui frontend to gate
        // action buttons (HIGH fix #5). Server still enforces every action;
        // these flags only drive UI rendering.
        $capabilities = [
            'reimbursement' => [
                'create'   => $has('reimbursement.create'),
                'edit_own' => $has('reimbursement.edit_own_draft'),
                'submit'   => $has('reimbursement.submit'),
                'review'   => $has('reimbursement.review'),
                'approve'  => $has('reimbursement.approve'),
                'reject'   => $has('reimbursement.reject'),
                'override' => $has('reimbursement.override_cap'),
            ],
            'attendance' => [
                'record'             => $has('attendance.record'),
                'request_correction' => $has('attendance.request_correction'),
                'review_correction'  => $has('attendance.review_correction'),
            ],
            'schedule' => [
                'view_assigned'      => $has('schedule.view_assigned'),
                'request_adjustment' => $has('schedule.request_adjustment'),
                'review_adjustment'  => $has('schedule.review_adjustment'),
            ],
            'budget' => [
                'view'             => $has('budget.view'),
                'manage_categories' => $has('budget.manage_categories'),
                'manage_allocations' => $has('budget.manage_allocations'),
            ],
            'settlement' => [
                'record'  => $has('settlement.record'),
                'confirm' => $has('settlement.confirm'),
                'refund'  => $has('settlement.refund'),
            ],
            'audit' => [
                'view'   => $has('audit.view'),
                'export' => $has('audit.export'),
                'unmask' => $has('sensitive.unmask'),
            ],
            'admin' => [
                'manage_users'       => $has('auth.manage_users'),
                'manage_roles'       => $has('auth.manage_roles'),
                'manage_permissions' => $has('auth.manage_permissions'),
            ],
            'is_global' => !empty($resolver->scopeFor((int)$user->id)['global']),
        ];

        return [
            'id'                => (int)$user->id,
            'username'          => $user->username,
            'display_name'      => $user->display_name,
            'status'            => $user->status,
            'must_change_password' => (bool)$user->must_change_password,
            'roles'             => $resolver->rolesFor((int)$user->id),
            'permissions'       => $perms,
            'scope'             => $resolver->scopeFor((int)$user->id),
            'capabilities'      => $capabilities,
        ];
    }
}
