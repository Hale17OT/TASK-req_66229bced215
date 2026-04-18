<?php
namespace Tests\api;

/**
 * Covers /api/v1/admin/roles{,/:id}  and  /api/v1/admin/permissions
 */
class RoleAdminApiTest extends ApiTestCase
{
    public function test_index_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/admin/roles');
        $this->assertStatus(401, $res);
    }

    public function test_index_lists_all_roles_with_permissions(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/roles');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        $keys = array_column($body['data'], 'key');
        foreach (['Administrator', 'FrontDesk', 'Coach', 'Finance', 'Operations'] as $r) {
            self::assertContains($r, $keys);
        }
    }

    public function test_create_rejects_invalid_key(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/admin/roles', [
            'key'  => 'has space!',
            'name' => 'Bad',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_create_rejects_duplicate_key(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/admin/roles', [
            'key' => 'Administrator',
            'name' => 'dup',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_create_update_delete_cycle(): void
    {
        $this->loginAs('admin');
        $key = 'TempRole_' . bin2hex(random_bytes(3));

        $created = $this->request('POST', '/api/v1/admin/roles', [
            'key' => $key, 'name' => 'Temp', 'description' => 'ephemeral',
            'permissions' => [],
        ]);
        $this->assertStatus(200, $created);
        $rid = (int)$this->json($created)['data']['id'];

        $updated = $this->request('PUT', "/api/v1/admin/roles/{$rid}", [
            'name' => 'Temp Updated',
        ]);
        $this->assertStatus(200, $updated);
        self::assertSame('Temp Updated', $this->json($updated)['data']['name']);

        $deleted = $this->request('DELETE', "/api/v1/admin/roles/{$rid}");
        $this->assertStatus(200, $deleted);
        $this->assertEnvelopeCode(0, $deleted);
    }

    public function test_destroy_system_role_is_forbidden(): void
    {
        $this->loginAs('admin');
        // Administrator role is is_system=1 — cannot delete
        $res = $this->request('DELETE', '/api/v1/admin/roles/1');
        $this->assertStatus(403, $res);
    }
}
