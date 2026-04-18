<?php
namespace Tests\unit;

use app\service\security\FieldCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MEDIUM fix audit-2 #6 — FieldCipher constructor refuses placeholder keys
 * outside test environments. Tests temporarily flip APP_ENV to assert both
 * branches of the guard.
 */
class FieldCipherStartupGuardTest extends TestCase
{
    private string $savedEnv = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedEnv = (string)getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->savedEnv === '') {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV']);
        } else {
            putenv('APP_ENV=' . $this->savedEnv);
            $_ENV['APP_ENV'] = $this->savedEnv;
        }
        parent::tearDown();
    }

    public function test_placeholder_key_accepted_in_test_env(): void
    {
        putenv('APP_ENV=test');
        $_ENV['APP_ENV'] = 'test';
        // Should NOT throw
        new FieldCipher('please_change_this_32_byte_secret_yy');
        $this->assertTrue(true);
    }

    public function test_placeholder_key_rejected_in_prod_env(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/placeholder/i');
        new FieldCipher('please_change_this_32_byte_secret_yy');
    }

    public function test_default_fallback_key_rejected_in_prod_env(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $this->expectException(RuntimeException::class);
        new FieldCipher('studio-console-default-encryption-key-change-me');
    }

    public function test_real_key_accepted_in_prod_env(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        new FieldCipher(bin2hex(random_bytes(32))); // simulated operator-supplied secret
        $this->assertTrue(true);
    }
}
