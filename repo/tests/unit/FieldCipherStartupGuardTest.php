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

    /**
     * audit-6 validator-break fix: docker-compose ships APP_ENV=development
     * by default. That branch must accept the shipped placeholder so a
     * fresh `docker compose up` boots without hand-generating secrets.
     * Production still rejects, proven by the tests above.
     */
    public function test_placeholder_key_accepted_in_development_env(): void
    {
        putenv('APP_ENV=development');
        $_ENV['APP_ENV'] = 'development';
        new FieldCipher('please_change_this_32_byte_secret_yy');
        $this->assertTrue(true);
    }

    public function test_placeholder_key_rejected_in_staging_env(): void
    {
        // staging is treated as production-ish for the secrets guard.
        putenv('APP_ENV=staging');
        $_ENV['APP_ENV'] = 'staging';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/placeholder/i');
        new FieldCipher('please_change_this_32_byte_secret_yy');
    }

    public function test_is_production_environment_classification(): void
    {
        $cases = [
            ['production', true],
            ['prod',       true],
            ['staging',    true],
            ['live',       true],
            ['test',       false],
            ['testing',    false],
            ['development',false],
            ['dev',        false],
            ['local',      false],
        ];
        foreach ($cases as [$env, $expectedProd]) {
            putenv('APP_ENV=' . $env);
            $_ENV['APP_ENV'] = $env;
            self::assertSame(
                $expectedProd,
                FieldCipher::isProductionEnvironment(),
                "APP_ENV={$env} should classify as prod={($expectedProd ? 'true' : 'false')}"
            );
        }
    }
}
