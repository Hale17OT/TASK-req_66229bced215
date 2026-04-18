<?php
namespace Tests\api;

class LocationDepartmentApiTest extends ApiTestCase
{
    public function test_get_locations_returns_seeded_rows(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/locations');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $codes = array_column($this->json($res)['data'], 'code');
        self::assertContains('HQ', $codes);
    }

    public function test_post_location_creates_new_row(): void
    {
        $this->loginAs('admin');
        $code = 'LOC' . strtoupper(bin2hex(random_bytes(2)));
        $res = $this->request('POST', '/api/v1/admin/locations', [
            'code' => $code, 'name' => 'Test Location',
        ]);
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertSame($code, $body['data']['code']);
    }

    public function test_post_location_rejects_duplicate_code(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/admin/locations', [
            'code' => 'HQ', 'name' => 'dup',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_get_departments_returns_seeded_rows(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/departments');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $codes = array_column($this->json($res)['data'], 'code');
        self::assertContains('OPS', $codes);
    }

    public function test_post_department_creates_new_row(): void
    {
        $this->loginAs('admin');
        $code = 'DEP' . strtoupper(bin2hex(random_bytes(2)));
        $res = $this->request('POST', '/api/v1/admin/departments', [
            'code' => $code, 'name' => 'Test Dept',
        ]);
        $this->assertStatus(200, $res);
        self::assertSame($code, $this->json($res)['data']['code']);
    }
}
