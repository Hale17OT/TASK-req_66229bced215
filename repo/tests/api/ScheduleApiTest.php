<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Covers /api/v1/schedule/entries and /schedule/adjustments
 */
class ScheduleApiTest extends ApiTestCase
{
    /** Insert a schedule entry directly for the coach user (id=3). */
    private function seedEntry(int $coachId = 3): int
    {
        return (int)Db::table('schedule_entries')->insertGetId([
            'coach_user_id' => $coachId,
            'location_id'   => 1,
            'starts_at'     => '2026-05-01 09:00:00',
            'ends_at'       => '2026-05-01 10:00:00',
            'title'         => 'Seeded class',
            'status'        => 'active',
            'created_by'    => 1,
        ]);
    }

    public function test_get_entries_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/schedule/entries');
        $this->assertStatus(401, $res);
    }

    public function test_get_entries_as_coach_sees_only_own(): void
    {
        $myId = $this->seedEntry(3);
        $otherId = $this->seedEntry(1); // admin's schedule
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/schedule/entries');
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        $ids = array_column($body['data']['data'] ?? $body['data'], 'id');
        self::assertContains($myId, $ids);
        self::assertNotContains($otherId, $ids);
    }

    public function test_get_adjustments_index(): void
    {
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/schedule/adjustments');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_post_adjustment_rejects_foreign_entry(): void
    {
        $other = $this->seedEntry(1); // admin's
        $this->loginAs('coach');
        $res = $this->request('POST', '/api/v1/schedule/adjustments', [
            'target_entry_id'  => $other,
            'reason'           => 'Trying to edit someone else\'s schedule entry',
            'proposed_changes' => ['starts_at' => '2026-05-01 10:00:00'],
        ]);
        $this->assertStatus(403, $res);
    }

    public function test_post_adjustment_submits_and_reviewer_approves(): void
    {
        $id = $this->seedEntry(3);
        $this->loginAs('coach');
        $sub = $this->request('POST', '/api/v1/schedule/adjustments', [
            'target_entry_id'  => $id,
            'reason'           => 'Moving this class one hour later because of venue issue',
            'proposed_changes' => ['starts_at' => '2026-05-01 11:00:00', 'ends_at' => '2026-05-01 12:00:00'],
        ]);
        $this->assertStatus(200, $sub);
        $adjId = (int)$this->json($sub)['data']['id'];

        // Switch to reviewer
        $this->forgetSession();
        $this->loginAs('operations');
        $res = $this->request('POST', "/api/v1/schedule/adjustments/{$adjId}/approve", ['comment' => 'ok']);
        $this->assertStatus(200, $res);
        self::assertSame('applied', $this->json($res)['data']['status']);
    }

    public function test_post_adjustment_reject_requires_comment(): void
    {
        $id = $this->seedEntry(3);
        $this->loginAs('coach');
        $sub = $this->request('POST', '/api/v1/schedule/adjustments', [
            'target_entry_id'  => $id,
            'reason'           => 'Swap requested by another coach for this slot',
            'proposed_changes' => [],
        ]);
        $adjId = (int)$this->json($sub)['data']['id'];
        $this->forgetSession();
        $this->loginAs('operations');
        $bad = $this->request('POST', "/api/v1/schedule/adjustments/{$adjId}/reject", ['comment' => 'no']);
        $this->assertStatus(422, $bad);
        $ok = $this->request('POST', "/api/v1/schedule/adjustments/{$adjId}/reject", [
            'comment' => 'Conflicts with another booking on the same slot'
        ]);
        $this->assertStatus(200, $ok);
        self::assertSame('rejected', $this->json($ok)['data']['status']);
    }

    public function test_post_adjustment_withdraw_by_requester(): void
    {
        $id = $this->seedEntry(3);
        $this->loginAs('coach');
        $sub = $this->request('POST', '/api/v1/schedule/adjustments', [
            'target_entry_id'  => $id,
            'reason'           => 'Will withdraw this — changed my mind',
            'proposed_changes' => [],
        ]);
        $adjId = (int)$this->json($sub)['data']['id'];
        $res = $this->request('POST', "/api/v1/schedule/adjustments/{$adjId}/withdraw");
        $this->assertStatus(200, $res);
        self::assertSame('withdrawn', $this->json($res)['data']['status']);
    }
}
