<?php
namespace app\service\auth;

use app\exception\BusinessException;
use app\model\PasswordHistory;
use app\model\User;

/**
 * Server-authoritative password policy (spec §9.1, §17.2).
 *
 *  - Min length 12, with upper/lower/digit/special
 *  - Hashed with Argon2id
 *  - Reuse blocked against last 5 hashes
 *  - 90-day rotation
 */
class PasswordPolicy
{
    public function __construct(private array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array)config('app.studio.password'));
    }

    public function validate(string $plain): array
    {
        $errors = [];
        if (strlen($plain) < (int)$this->config['min_length']) {
            $errors[] = "Password must be at least {$this->config['min_length']} characters.";
        }
        if (!empty($this->config['require_upper'])   && !preg_match('/[A-Z]/', $plain))     $errors[] = 'Password must contain an uppercase letter.';
        if (!empty($this->config['require_lower'])   && !preg_match('/[a-z]/', $plain))     $errors[] = 'Password must contain a lowercase letter.';
        if (!empty($this->config['require_digit'])   && !preg_match('/\d/',   $plain))      $errors[] = 'Password must contain a digit.';
        if (!empty($this->config['require_special']) && !preg_match('/[^A-Za-z0-9]/', $plain)) $errors[] = 'Password must contain a special character.';
        return $errors;
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function isReused(int $userId, string $plain): bool
    {
        $window = (int)$this->config['history_window'];
        $rows = PasswordHistory::where('user_id', $userId)
            ->order('created_at', 'desc')->limit($window)->select();
        foreach ($rows as $row) {
            if ($this->verify($plain, $row->password_hash)) return true;
        }
        return false;
    }

    /** Throws BusinessException with all collected errors if invalid. */
    public function assertAcceptable(int $userId, string $plain): void
    {
        $errs = $this->validate($plain);
        if (!empty($errs)) {
            throw new BusinessException('Password does not meet policy', 40020, 422, ['password' => $errs]);
        }
        if ($userId && $this->isReused($userId, $plain)) {
            throw new BusinessException('Password has been used recently', 40021, 422,
                ['password' => ['Cannot reuse one of your last ' . $this->config['history_window'] . ' passwords.']]
            );
        }
    }

    public function setForUser(User $user, string $plain): void
    {
        $hash = $this->hash($plain);
        $user->password_hash = $hash;
        $user->password_changed_at = date('Y-m-d H:i:s');
        $user->must_change_password = 0;
        if ($user->status === 'password_expired') $user->status = 'active';
        $user->save();
        PasswordHistory::create([
            'user_id'       => $user->id,
            'password_hash' => $hash,
        ]);
    }

    public function isExpired(User $user): bool
    {
        $days = (int)$this->config['rotation_days'];
        if ($days <= 0) return false;
        $changed = strtotime((string)$user->password_changed_at);
        return $changed > 0 && (time() - $changed) > ($days * 86400);
    }
}
