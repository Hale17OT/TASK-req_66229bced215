<?php
namespace Tests\api;

/**
 * Covers /api/v1/auth/{login,logout,me,password/change}
 */
class AuthApiTest extends ApiTestCase
{
    public function test_post_login_rejects_bad_credentials(): void
    {
        $res = $this->request('POST', '/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);
        $this->assertStatus(401, $res);
        $this->assertEnvelopeCode(40100, $res);
    }

    public function test_post_login_rejects_empty_fields(): void
    {
        $res = $this->request('POST', '/api/v1/auth/login', [
            'username' => '',
            'password' => '',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_post_login_succeeds_and_returns_me_snapshot(): void
    {
        $res = $this->loginAs('admin');
        $body = $this->json($res);
        self::assertSame('admin', $body['data']['username']);
        self::assertContains('Administrator', $body['data']['roles']);
        self::assertContains('auth.manage_users', $body['data']['permissions']);
        self::assertTrue($body['data']['scope']['global']);
        self::assertNotNull($this->csrfToken, 'login must set studio_csrf cookie');
    }

    public function test_get_me_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/auth/me');
        $this->assertStatus(401, $res);
        $this->assertEnvelopeCode(40100, $res);
    }

    public function test_get_me_returns_current_user_after_login(): void
    {
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/auth/me');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertSame('finance', $body['data']['username']);
        self::assertContains('Finance', $body['data']['roles']);
    }

    public function test_post_password_change_wrong_current_is_rejected(): void
    {
        $this->loginAs('coach');
        $res = $this->request('POST', '/api/v1/auth/password/change', [
            'current_password' => 'definitely-not-the-pw',
            'new_password'     => 'NewStrong!Pass#2026',
        ]);
        $this->assertStatus(422, $res);
        $this->assertEnvelopeCode(40022, $res);
    }

    public function test_post_password_change_requires_csrf(): void
    {
        $this->loginAs('coach');
        // Deliberately drop the csrf token before sending
        $this->csrfToken = null;
        $res = $this->request('POST', '/api/v1/auth/password/change', [
            'current_password' => self::DEMO_PASSWORD,
            'new_password'     => 'AnotherPass!2026',
        ], ['x-csrf-token' => 'bogus']);
        $this->assertStatus(403, $res);
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_post_password_change_succeeds_with_valid_current(): void
    {
        $this->loginAs('operations');
        $res = $this->request('POST', '/api/v1/auth/password/change', [
            'current_password' => self::DEMO_PASSWORD,
            'new_password'     => 'BrandNew!Pass#2026',
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_post_logout_destroys_session(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/auth/logout');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);

        // Subsequent /me without a fresh login: current session cookie still in jar
        // but backing session file is destroyed server-side → expect 401.
        $res2 = $this->request('GET', '/api/v1/auth/me');
        $this->assertStatus(401, $res2);
    }
}
