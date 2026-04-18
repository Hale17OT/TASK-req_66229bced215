<?php
namespace Tests\api;

class PermissionApiTest extends ApiTestCase
{
    public function test_index_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/admin/permissions');
        $this->assertStatus(401, $res);
    }

    public function test_index_returns_full_permission_catalog(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/permissions');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertIsArray($body['data']);
        self::assertGreaterThanOrEqual(30, count($body['data']));
        $keys = array_column($body['data'], 'key');
        self::assertContains('reimbursement.approve', $keys);
        self::assertContains('audit.view', $keys);
    }
}
