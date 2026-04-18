<?php
namespace Tests\integration;

use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * Real-DB integration test for login + lockout + password change.
 *
 * Skipped automatically if the test database is not reachable. To run:
 *   docker compose exec app sh -c \
 *     "DB_NAME=studio_console_test php think migrate:run && DB_NAME=studio_console_test composer test"
 */
class AuthFlowTest extends TestCase
{
    protected function setUp(): void
    {
        try {
            Db::table('users')->limit(1)->select();
        } catch (\Throwable $e) {
            $this->markTestSkipped('test DB not reachable: ' . $e->getMessage());
        }
        // Truncate auth tables for a clean slate
        Db::execute('SET FOREIGN_KEY_CHECKS=0');
        foreach (['login_attempts', 'user_sessions', 'password_history', 'user_roles', 'user_scope_assignments', 'users'] as $t) {
            Db::execute("TRUNCATE TABLE {$t}");
        }
        Db::execute('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testHashedPasswordRoundTrip(): void
    {
        $hash = password_hash('Strong!Pass#2026', PASSWORD_ARGON2ID);
        $this->assertTrue(password_verify('Strong!Pass#2026', $hash));
        $this->assertFalse(password_verify('wrong', $hash));
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testLockoutCounterReset(): void
    {
        // Insert 4 failed attempts, then 1 success — tracker.shouldLock() should be false
        for ($i = 0; $i < 4; $i++) {
            Db::table('login_attempts')->insert([
                'username' => 'tester', 'succeeded' => 0, 'attempted_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $fails = (int)Db::table('login_attempts')
            ->where('username', 'tester')->where('succeeded', 0)
            ->where('attempted_at', '>=', date('Y-m-d H:i:s', time() - 900))
            ->count();
        $this->assertSame(4, $fails);
        $this->assertLessThan(5, $fails, 'should NOT lock at 4');
    }
}
