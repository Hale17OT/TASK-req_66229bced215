<?php
namespace app\service\security;

use RuntimeException;

/**
 * Authenticated symmetric encryption for finance-sensitive columns.
 *
 *   - Algorithm: libsodium `crypto_secretbox` (XSalsa20 + Poly1305).
 *   - Key       : `ENCRYPTION_KEY` env var, hashed to 32 bytes via SHA-256
 *                 so any operator-supplied string yields a valid key.
 *   - Format    : `enc:v1:<base64(nonce|ciphertext)>`
 *   - Blind idx : HMAC-SHA-256(plaintext, key) — used so we can still answer
 *                 equality lookups (e.g. duplicate-receipt detection) without
 *                 storing the plaintext.
 *
 * Fail-open behaviour: `decrypt()` returns the input unchanged if it does NOT
 * carry the `enc:v1:` prefix — this lets the same code path serve rows that
 * were written before the encryption migration was applied. New writes always
 * encrypt.
 */
final class FieldCipher
{
    public const PREFIX = 'enc:v1:';

    /**
     * Placeholder strings shipped in `.env.example` and the docker-compose
     * fallback. If FieldCipher is initialized with one of these in a
     * non-test environment we fail closed — production deployments MUST
     * override ENCRYPTION_KEY before boot. Tests are exempt because they
     * intentionally run against a deterministic well-known key.
     */
    public const KNOWN_INSECURE_KEYS = [
        'please_change_this_32_byte_secret_yy',
        'studio-console-default-encryption-key-change-me',
    ];

    private string $key;

    public function __construct(?string $rawKey = null)
    {
        $rawKey = $rawKey ?? (string)getenv('ENCRYPTION_KEY');
        if ($rawKey === '') {
            throw new RuntimeException('ENCRYPTION_KEY env var must be set for FieldCipher');
        }
        if (!self::isTestEnvironment() && in_array($rawKey, self::KNOWN_INSECURE_KEYS, true)) {
            throw new RuntimeException(
                'ENCRYPTION_KEY is set to a known placeholder value. '
                . 'Generate a real 32-byte secret (e.g. `openssl rand -hex 32`) '
                . 'and override the env var before starting the app in this environment.'
            );
        }
        // SHA-256 → exactly 32 bytes regardless of input length
        $this->key = hash('sha256', $rawKey, true);
    }

    /**
     * Test environment detection.
     *
     * Resolution order (an explicit APP_ENV ALWAYS wins, so the prod guard
     * can be exercised even from inside a phpunit run):
     *   1. APP_ENV=test|testing                     → test  (true)
     *   2. APP_ENV=prod|production|staging|live     → prod  (false)
     *   3. APP_ENV unset → check for phpunit on the stack/script.
     */
    public static function isTestEnvironment(): bool
    {
        $env = strtolower((string)(getenv('APP_ENV') ?: $_ENV['APP_ENV'] ?? ''));
        if ($env === 'test' || $env === 'testing') return true;
        if (in_array($env, ['prod', 'production', 'staging', 'live'], true)) return false;
        // No explicit env signal — fall back to phpunit detection.
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) return true;
        $script = (string)(($_SERVER['SCRIPT_NAME'] ?? '') . ' ' . ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        return str_contains($script, 'phpunit');
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') return $plain;
        if (str_starts_with($plain, self::PREFIX)) return $plain; // already encrypted
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = sodium_crypto_secretbox($plain, $nonce, $this->key);
        return self::PREFIX . base64_encode($nonce . $ct);
    }

    public function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') return $cipher;
        if (!str_starts_with($cipher, self::PREFIX)) return $cipher;
        $raw = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $body = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($body, $nonce, $this->key);
        return $plain === false ? null : $plain;
    }

    /** HMAC-SHA-256 hex digest — deterministic, irreversible. */
    public function blindIndex(?string $plain): ?string
    {
        if ($plain === null || $plain === '') return null;
        return hash_hmac('sha256', $plain, $this->key);
    }

    /** Last-4 mask — `***1234`. Returns empty string for empty input. */
    public function mask(?string $plain): string
    {
        if ($plain === null || $plain === '') return '';
        $n = mb_strlen($plain);
        if ($n <= 4) return str_repeat('*', $n);
        return str_repeat('*', $n - 4) . mb_substr($plain, -4);
    }

    public static function fromEnv(): self
    {
        $key = (string)(getenv('ENCRYPTION_KEY') ?: $_ENV['ENCRYPTION_KEY'] ?? '');
        if ($key === '') {
            if (!self::isTestEnvironment()) {
                throw new RuntimeException(
                    'ENCRYPTION_KEY is not set. Generate a real 32-byte secret '
                    . '(e.g. `openssl rand -hex 32`) and pass it through the '
                    . 'app container env before booting.'
                );
            }
            // Test runs only: stable key so deterministic round-trips work.
            $key = 'studio-console-default-encryption-key-change-me';
        }
        return new self($key);
    }
}
