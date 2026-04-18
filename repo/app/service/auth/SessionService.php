<?php
namespace app\service\auth;

use app\model\User;
use app\model\UserSession;
use think\facade\Request;

/**
 * Server-side session record management (spec §9.1, §14.2):
 *  - max 3 concurrent active sessions per user (oldest revoked)
 *  - 30-min idle timeout, 12-h absolute lifetime
 *  - device fingerprint for inspection screen
 */
class SessionService
{
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array)config('app.studio.session'));
    }

    public function fingerprint(Request|\think\Request $request): string
    {
        $ua = (string)$request->header('user-agent', '');
        $al = (string)$request->header('accept-language', '');
        $ip = (string)$request->ip();
        return substr(hash('sha256', $ua . '|' . $al . '|' . $ip), 0, 64);
    }

    public function start(User $user, string $sessionId, \think\Request $request): UserSession
    {
        $now = time();
        $row = UserSession::create([
            'session_id'         => hash('sha256', $sessionId),
            'user_id'            => $user->id,
            'ip'                 => $request->ip(),
            'user_agent'         => substr((string)$request->header('user-agent', ''), 0, 255),
            'device_fingerprint' => $this->fingerprint($request),
            'created_at'         => date('Y-m-d H:i:s', $now),
            'last_activity_at'   => date('Y-m-d H:i:s', $now),
            'expires_at'         => date('Y-m-d H:i:s', $now + (int)$this->config['absolute_lifetime_sec']),
        ]);
        $this->enforceConcurrentLimit($user);
        return $row;
    }

    public function findValid(string $sessionId): ?UserSession
    {
        $row = UserSession::where('session_id', hash('sha256', $sessionId))
            ->whereNull('revoked_at')
            ->find();
        if (!$row) return null;
        $now = time();
        if (strtotime((string)$row->expires_at) <= $now) {
            $this->revoke($row, 'expired_absolute');
            return null;
        }
        if ($now - strtotime((string)$row->last_activity_at) > (int)$this->config['idle_timeout_seconds']) {
            $this->revoke($row, 'expired_idle');
            return null;
        }
        return $row;
    }

    public function touch(UserSession $row): void
    {
        $row->last_activity_at = date('Y-m-d H:i:s');
        $row->save();
    }

    public function revoke(UserSession $row, string $reason = 'user', ?int $by = null): void
    {
        $row->revoked_at = date('Y-m-d H:i:s');
        $row->revoke_reason = $reason;
        $row->revoked_by = $by;
        $row->save();
    }

    public function revokeAllForUser(int $userId, ?int $by = null, string $reason = 'admin'): int
    {
        return UserSession::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at'    => date('Y-m-d H:i:s'),
                'revoked_by'    => $by,
                'revoke_reason' => $reason,
            ]);
    }

    public function activeForUser(int $userId): array
    {
        return UserSession::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->order('last_activity_at', 'desc')
            ->select()->toArray();
    }

    private function enforceConcurrentLimit(User $user): void
    {
        $max = (int)$this->config['max_concurrent'];
        $rows = UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->order('last_activity_at', 'desc')
            ->select();
        if (count($rows) <= $max) return;
        $excess = array_slice($rows->toArray(), $max);
        foreach ($excess as $r) {
            $row = UserSession::find($r['id']);
            if ($row) $this->revoke($row, 'concurrent_limit');
        }
    }
}
