<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Covers /api/v1/attendance/corrections {GET, POST, :id/approve, :id/reject, :id/withdraw}
 */
class AttendanceCorrectionApiTest extends ApiTestCase
{
    /** Create an attendance record via the API and return its id. */
    private function seedAttendance(): int
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/attendance/records', [
            'location_id' => 1, 'member_name' => 'Seed', 'attendance_type' => 'class',
        ]);
        $this->assertStatus(200, $res);
        return (int)$this->json($res)['data']['id'];
    }

    public function test_get_corrections_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/attendance/corrections');
        $this->assertStatus(401, $res);
    }

    public function test_get_corrections_lists_rows(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/attendance/corrections');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_post_corrections_rejects_short_reason(): void
    {
        $attId = $this->seedAttendance();
        $res = $this->request('POST', '/api/v1/attendance/corrections', [
            'target_attendance_id' => $attId,
            'reason'               => 'too short',
            'proposed_payload'     => ['member_name' => 'x'],
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_post_corrections_submits_with_valid_payload(): void
    {
        $attId = $this->seedAttendance();
        $res = $this->request('POST', '/api/v1/attendance/corrections', [
            'target_attendance_id' => $attId,
            'reason'               => 'Wrong member name on original row, fixing',
            'proposed_payload'     => ['member_name' => 'Corrected Name'],
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertSame('submitted', $body['data']['status']);
    }

    public function test_post_corrections_approve_applies_correction(): void
    {
        $attId = $this->seedAttendance();
        $submit = $this->request('POST', '/api/v1/attendance/corrections', [
            'target_attendance_id' => $attId,
            'reason'               => 'Fixing attendance data per front desk request',
            'proposed_payload'     => ['member_name' => 'After Approve'],
        ]);
        $corId = (int)$this->json($submit)['data']['id'];

        $res = $this->request('POST', "/api/v1/attendance/corrections/{$corId}/approve", [
            'comment' => 'ok',
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        self::assertSame('applied', $this->json($res)['data']['status']);
        // Original must be superseded — not destroyed
        $original = Db::table('attendance_records')->where('id', $attId)->find();
        self::assertSame('superseded', $original['status']);
    }

    public function test_post_corrections_reject_requires_comment(): void
    {
        $attId = $this->seedAttendance();
        $submit = $this->request('POST', '/api/v1/attendance/corrections', [
            'target_attendance_id' => $attId,
            'reason'               => 'Test rejection path with valid reason',
            'proposed_payload'     => [],
        ]);
        $corId = (int)$this->json($submit)['data']['id'];

        $short = $this->request('POST', "/api/v1/attendance/corrections/{$corId}/reject", [
            'comment' => 'nope',
        ]);
        $this->assertStatus(422, $short);

        $ok = $this->request('POST', "/api/v1/attendance/corrections/{$corId}/reject", [
            'comment' => 'Rejected because the proposed value is incorrect',
        ]);
        $this->assertStatus(200, $ok);
        $this->assertEnvelopeCode(0, $ok);
        self::assertSame('rejected', $this->json($ok)['data']['status']);
    }

    public function test_post_corrections_withdraw_by_requester(): void
    {
        $attId = $this->seedAttendance();
        $submit = $this->request('POST', '/api/v1/attendance/corrections', [
            'target_attendance_id' => $attId,
            'reason'               => 'Changed my mind — will withdraw this later',
            'proposed_payload'     => [],
        ]);
        $corId = (int)$this->json($submit)['data']['id'];

        $res = $this->request('POST', "/api/v1/attendance/corrections/{$corId}/withdraw");
        $this->assertStatus(200, $res);
        self::assertSame('withdrawn', $this->json($res)['data']['status']);
    }
}
