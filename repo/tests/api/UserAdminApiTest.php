<?php
namespace Tests\api;

/**
 * Covers /api/v1/admin/users{,/:id,/:id/reset-password,/:id/lock,/:id/unlock,/:id/sessions}
 */
class UserAdminApiTest extends ApiTestCase
{
    public function test_index_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/admin/users');
        $this->assertStatus(401, $res);
    }

    public function test_index_requires_manage_users(): void
    {
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/admin/users');
        $this->assertStatus(403, $res);
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/users?page=1&size=50');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('data', $body['data']);
        self::assertGreaterThan(0, $body['data']['total']);
    }

    public function test_show_returns_user_with_scope_and_permissions(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/users/1');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertSame('admin', $body['data']['username']);
        self::assertArrayHasKey('scope', $body['data']);
        self::assertArrayHasKey('permissions', $body['data']);
    }

    public function test_show_nonexistent_404(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/users/99999');
        $this->assertStatus(404, $res);
    }

    public function test_create_rejects_invalid_username(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/admin/users', [
            'username'     => 'a b',          // invalid — contains space
            'display_name' => 'Bad User',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_create_rejects_duplicate_username(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/admin/users', [
            'username'     => 'admin',      // already exists
            'display_name' => 'Another',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_create_then_show_new_user(): void
    {
        $this->loginAs('admin');
        $uname = 'tempuser_' . bin2hex(random_bytes(3));
        $res = $this->request('POST', '/api/v1/admin/users', [
            'username'     => $uname,
            'display_name' => 'Temp',
            'roles'        => [],
            'scopes'       => [],
        ]);
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('id', $body['data']);
        self::assertArrayHasKey('temp_password', $body['data']);
        $id = (int)$body['data']['id'];

        $show = $this->request('GET', '/api/v1/admin/users/' . $id);
        $this->assertEnvelopeCode(0, $show);
        self::assertSame($uname, $this->json($show)['data']['username']);
    }

    public function test_update_changes_display_name(): void
    {
        $this->loginAs('admin');
        $res = $this->request('PUT', '/api/v1/admin/users/2', [
            'display_name' => 'Updated Display',
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertSame('Updated Display', $body['data']['display_name']);
    }

    public function test_update_with_stale_version_returns_409(): void
    {
        $this->loginAs('admin');
        $res = $this->request('PUT', '/api/v1/admin/users/2', [
            'version'      => 999,
            'display_name' => 'Stale',
        ]);
        $this->assertStatus(409, $res);
    }

    public function test_reset_password_issues_temp_credential(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/admin/users/2/reset-password');
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('temp_password', $body['data']);
        self::assertGreaterThanOrEqual(12, strlen($body['data']['temp_password']));
    }

    public function test_lock_and_unlock_cycle(): void
    {
        $this->loginAs('admin');
        $lock = $this->request('POST', '/api/v1/admin/users/3/lock');
        $this->assertStatus(200, $lock);
        $this->assertEnvelopeCode(0, $lock);

        $after = $this->json($this->request('GET', '/api/v1/admin/users/3'));
        self::assertSame('locked', $after['data']['status']);

        $unlock = $this->request('POST', '/api/v1/admin/users/3/unlock');
        $this->assertStatus(200, $unlock);
        $this->assertEnvelopeCode(0, $unlock);

        $after2 = $this->json($this->request('GET', '/api/v1/admin/users/3'));
        self::assertSame('active', $after2['data']['status']);
    }

    public function test_revoke_all_sessions_returns_count(): void
    {
        $this->loginAs('admin');
        $res = $this->request('DELETE', '/api/v1/admin/users/2/sessions');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('count', $body['data']);
    }
}
