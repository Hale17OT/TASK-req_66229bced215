<?php
namespace app\service\auth;

use app\model\LoginAttempt;
use app\model\User;

/**
 * Failed-login lockout (spec §9.1):
 *   5 failures within 15 minutes → lock for 30 minutes.
 */
class LockoutTracker
{
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array)config('app.studio.lockout'));
    }

    public function recordAttempt(string $username, ?string $ip, bool $success, ?string $reason = null): void
    {
        LoginAttempt::create([
            'username'  => $username,
            'ip'        => $ip,
            'succeeded' => $success ? 1 : 0,
            'reason'    => $reason,
        ]);
    }

    public function shouldLock(string $username): bool
    {
        $window = (int)$this->config['window_seconds'];
        $threshold = (int)$this->config['fail_threshold'];
        $since = date('Y-m-d H:i:s', time() - $window);
        $fails = LoginAttempt::where('username', $username)
            ->where('succeeded', 0)
            ->where('attempted_at', '>=', $since)
            ->count();
        return $fails >= $threshold;
    }

    public function lockUser(User $user): void
    {
        $until = date('Y-m-d H:i:s', time() + (int)$this->config['cooldown_seconds']);
        $user->status = 'locked';
        $user->locked_until = $until;
        $user->save();
    }

    public function maybeReleaseLock(User $user): void
    {
        if ($user->status === 'locked' && $user->locked_until
            && strtotime((string)$user->locked_until) <= time()) {
            $user->status = 'active';
            $user->locked_until = null;
            $user->failed_login_count = 0;
            $user->save();
        }
    }
}
