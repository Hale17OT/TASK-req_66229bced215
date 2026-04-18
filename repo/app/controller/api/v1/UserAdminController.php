<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\Role;
use app\model\User;
use app\model\UserScope;
use app\service\audit\AuditService;
use app\service\auth\PasswordPolicy;
use app\service\auth\PermissionResolver;
use app\service\auth\SessionService;
use think\facade\Db;
use think\Response;

class UserAdminController extends BaseController
{
    private function requirePerm(string $perm): void
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, $perm)) {
            throw new AuthorizationException('Missing permission: ' . $perm);
        }
    }

    public function index(): Response
    {
        $this->requirePerm('auth.manage_users');
        $page = max(1, (int)$this->request->get('page', 1));
        $size = min(200, max(10, (int)$this->request->get('size', 50)));
        $q = User::order('id', 'asc');
        if ($search = trim((string)$this->request->get('q', ''))) {
            $q->whereLike('username|display_name', "%{$search}%");
        }
        $rows = $q->paginate(['list_rows' => $size, 'page' => $page]);
        return json_response(0, 'ok', $rows);
    }

    public function show($id): Response
    {
        $this->requirePerm('auth.manage_users');
        $u = User::with(['roles'])->find($id);
        if (!$u) throw new BusinessException('User not found', 40400, 404);
        $resolver = app()->make(PermissionResolver::class);
        $arr = $u->toArray();
        $arr['scope'] = $resolver->scopeFor((int)$u->id);
        $arr['permissions'] = $resolver->permissionsFor((int)$u->id);
        return json_response(0, 'ok', $arr);
    }

    public function create(): Response
    {
        $this->requirePerm('auth.manage_users');
        $data = $this->request->only(['username', 'display_name', 'roles', 'scopes', 'temp_password'], 'post');
        $username = trim((string)($data['username'] ?? ''));
        $display = trim((string)($data['display_name'] ?? ''));
        $roleIds = (array)($data['roles'] ?? []);
        $scopes  = (array)($data['scopes'] ?? []);
        $temp    = (string)($data['temp_password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.\-]{4,64}$/', $username)) {
            throw new BusinessException('Username invalid', 40000, 422,
                ['username' => ['4-64 chars, letters/digits/_/./-']]);
        }
        if (User::where('username', $username)->find()) {
            throw new BusinessException('Username already exists', 40001, 422, ['username' => ['Already exists']]);
        }
        $policy = PasswordPolicy::fromConfig();
        if ($temp === '') $temp = bin2hex(random_bytes(8)) . 'A!1';
        $policy->assertAcceptable(0, $temp);

        $user = Db::transaction(function () use ($username, $display, $roleIds, $scopes, $temp, $policy) {
            $u = User::create([
                'username'      => $username,
                'display_name' => $display ?: $username,
                'password_hash' => $policy->hash($temp),
                'status'        => 'password_expired', // force change on first login
                'must_change_password' => 1,
                'created_by'    => (int)$this->request->userId,
            ]);
            \app\model\PasswordHistory::create(['user_id' => $u->id, 'password_hash' => $u->password_hash]);
            foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
                if (Role::find($rid)) {
                    Db::table('user_roles')->insert([
                        'user_id' => $u->id, 'role_id' => $rid,
                        'assigned_by' => (int)$this->request->userId,
                    ]);
                }
            }
            foreach ($scopes as $s) {
                UserScope::create([
                    'user_id'      => $u->id,
                    'location_id'  => $s['location_id'] ?? null,
                    'department_id' => $s['department_id'] ?? null,
                    'is_global'    => !empty($s['is_global']) ? 1 : 0,
                    'assigned_by' => (int)$this->request->userId,
                ]);
            }
            return $u;
        });
        app()->make(AuditService::class)->record('user.created', 'user', $user->id, null, $user->toArray(), [
            'roles' => $roleIds, 'scopes' => $scopes, 'temp_password_issued' => true,
        ]);
        return json_response(0, 'ok', ['id' => (int)$user->id, 'temp_password' => $temp]);
    }

    public function update($id): Response
    {
        $this->requirePerm('auth.manage_users');
        $u = User::find($id);
        if (!$u) throw new BusinessException('User not found', 40400, 404);
        $data = $this->request->only(['display_name', 'status', 'roles', 'scopes', 'version'], 'put');
        if (isset($data['version']) && (int)$data['version'] !== (int)$u->version) {
            throw new BusinessException('Stale version — reload before saving', 40913, 409);
        }

        // MEDIUM fix audit-3 #5: snapshot the user's CURRENT roles + scopes
        // BEFORE mutation so the audit row carries a structured before/after
        // delta. Without this, role-removal events were silent in the audit
        // tail (only the user row's scalar columns were captured).
        $before          = $u->toArray();
        $beforeRoles     = $this->currentRoleKeys((int)$u->id);
        $beforeScopes    = $this->currentScopeRows((int)$u->id);

        Db::transaction(function () use ($u, $data) {
            if (isset($data['display_name'])) $u->display_name = (string)$data['display_name'];
            if (isset($data['status']) && in_array($data['status'], ['active', 'disabled', 'locked', 'password_expired'], true)) {
                $u->status = $data['status'];
            }
            $u->version = (int)$u->version + 1;
            $u->save();
            if (array_key_exists('roles', $data)) {
                Db::table('user_roles')->where('user_id', $u->id)->delete();
                foreach (array_unique(array_map('intval', (array)$data['roles'])) as $rid) {
                    if (Role::find($rid)) {
                        Db::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => $rid, 'assigned_by' => $this->request->userId]);
                    }
                }
            }
            if (array_key_exists('scopes', $data)) {
                UserScope::where('user_id', $u->id)->delete();
                foreach ((array)$data['scopes'] as $s) {
                    UserScope::create([
                        'user_id'      => $u->id,
                        'location_id'  => $s['location_id'] ?? null,
                        'department_id' => $s['department_id'] ?? null,
                        'is_global'    => !empty($s['is_global']) ? 1 : 0,
                        'assigned_by'  => $this->request->userId,
                    ]);
                }
            }
        });
        $afterRoles  = $this->currentRoleKeys((int)$u->id);
        $afterScopes = $this->currentScopeRows((int)$u->id);

        app()->make(AuditService::class)->record(
            'user.updated', 'user', $u->id, $before, $u->toArray(),
            [
                'roles_before'   => $beforeRoles,
                'roles_after'    => $afterRoles,
                'roles_added'    => array_values(array_diff($afterRoles, $beforeRoles)),
                'roles_removed'  => array_values(array_diff($beforeRoles, $afterRoles)),
                'scopes_before'  => $beforeScopes,
                'scopes_after'   => $afterScopes,
            ]
        );
        return json_response(0, 'ok', $u->toArray());
    }

    /** Sorted, deduped role-key list for the user (stable diffs in audit). */
    private function currentRoleKeys(int $userId): array
    {
        $rows = Db::table('user_roles')->alias('ur')
            ->leftJoin('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->whereNotNull('r.key')
            ->column('r.key');
        sort($rows);
        return array_values(array_unique($rows));
    }

    /** Canonical scope rows: list of {location_id, department_id, is_global}. */
    private function currentScopeRows(int $userId): array
    {
        $rows = Db::table('user_scope_assignments')
            ->where('user_id', $userId)
            ->field(['location_id', 'department_id', 'is_global'])
            ->order('is_global', 'desc')
            ->order('location_id')
            ->order('department_id')
            ->select()->toArray();
        return array_map(static fn ($r) => [
            'location_id'   => $r['location_id'] !== null ? (int)$r['location_id'] : null,
            'department_id' => $r['department_id'] !== null ? (int)$r['department_id'] : null,
            'is_global'     => (bool)$r['is_global'],
        ], $rows);
    }

    public function resetPassword($id): Response
    {
        $this->requirePerm('auth.manage_users');
        $u = User::find($id);
        if (!$u) throw new BusinessException('User not found', 40400, 404);
        $temp = bin2hex(random_bytes(8)) . 'A!1';
        $policy = PasswordPolicy::fromConfig();
        Db::transaction(function () use ($u, $policy, $temp) {
            $policy->setForUser($u, $temp);
            $u->status = 'password_expired';
            $u->must_change_password = 1;
            $u->save();
            SessionService::fromConfig()->revokeAllForUser((int)$u->id, (int)$this->request->userId, 'admin_reset');
        });
        app()->make(AuditService::class)->record('user.password.reset', 'user', $u->id, null, null, ['by' => 'admin', 'temp_issued' => true]);
        return json_response(0, 'ok', ['temp_password' => $temp]);
    }

    public function lock($id): Response
    {
        $this->requirePerm('auth.manage_users');
        $u = User::find($id);
        if (!$u) throw new BusinessException('User not found', 40400, 404);
        $u->status = 'locked';
        $u->locked_until = date('Y-m-d H:i:s', time() + 365 * 86400); // until admin unlock
        $u->save();
        app()->make(AuditService::class)->record('user.locked', 'user', $u->id, null, null, ['by' => 'admin']);
        return json_response(0, 'locked');
    }

    public function unlock($id): Response
    {
        $this->requirePerm('auth.manage_users');
        $u = User::find($id);
        if (!$u) throw new BusinessException('User not found', 40400, 404);
        $u->status = 'active';
        $u->locked_until = null;
        $u->failed_login_count = 0;
        $u->save();
        app()->make(AuditService::class)->record('user.unlocked', 'user', $u->id, null, null, ['by' => 'admin']);
        return json_response(0, 'unlocked');
    }

    public function revokeAllSessions($id): Response
    {
        $this->requirePerm('auth.manage_users');
        $count = SessionService::fromConfig()->revokeAllForUser((int)$id, (int)$this->request->userId, 'admin_revoke');
        app()->make(AuditService::class)->record('user.sessions.revoked', 'user', $id, null, null, ['count' => $count]);
        return json_response(0, 'ok', ['count' => $count]);
    }
}
