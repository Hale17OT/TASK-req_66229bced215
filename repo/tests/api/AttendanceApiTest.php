<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Covers /api/v1/attendance/records {GET, POST}
 */
class AttendanceApiTest extends ApiTestCase
{
    public function test_get_records_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/attendance/records');
        $this->assertStatus(401, $res);
    }

    public function test_get_records_returns_paginated_list_for_admin(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/attendance/records');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('data', $body['data']);
    }

    public function test_get_records_filters_by_location(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/attendance/records?location_id=1&from=2026-01-01&to=2026-12-31');
        $this->assertStatus(200, $res);
    }

    public function test_post_records_requires_permission(): void
    {
        $this->loginAs('finance'); // finance has no attendance.record
        $res = $this->request('POST', '/api/v1/attendance/records', [
            'location_id' => 1, 'member_name' => 'Test',
        ]);
        $this->assertStatus(403, $res);
    }

    public function test_post_records_rejects_missing_location(): void
    {
        $this->loginAs('frontdesk');
        $res = $this->request('POST', '/api/v1/attendance/records', [
            'member_name' => 'Missing location',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_post_records_creates_row_and_writes_audit(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/attendance/records', [
            'location_id' => 1,
            'member_name' => 'Demo Member',
            'member_reference' => 'm-001',
            'attendance_type' => 'class',
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertSame('Demo Member', $body['data']['member_name']);
        // Verify the audit entry exists
        $cnt = Db::table('audit_logs')->where('action', 'attendance.recorded')->count();
        self::assertGreaterThan(0, $cnt);
    }
}
