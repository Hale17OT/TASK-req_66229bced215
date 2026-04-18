<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Regression tests for the second-round audit findings.
 *   #1 — Export show/download is private to the requester.
 *   #2 — budget_utilization CSV honors caller's allocation scope.
 *   #3 — Attendance correction approve/reject enforces row-level scope.
 *   #4 — Schedule adjustment approve/reject enforces row-level scope.
 *   #5 — Ledger listing scope-clipped via parent settlement → reimbursement.
 */
class Audit2FindingsApiTest extends ApiTestCase
{
    private int $hq;
    private int $north;
    private int $financeId;
    private int $opsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $this->north = $this->ensureLocation('NORTH', 'North Studio');
        $this->financeId = (int)Db::table('users')->where('username', 'finance')->value('id');
        $this->opsId = (int)Db::table('users')->where('username', 'operations')->value('id');

        // Finance pinned to NORTH, Operations pinned to NORTH (so HQ rows
        // are out of scope for both). Keep coach/frontdesk neutral.
        Db::execute('DELETE FROM user_scope_assignments WHERE user_id IN (?, ?)',
            [$this->financeId, $this->opsId]);
        Db::table('user_scope_assignments')->insertAll([
            ['user_id' => $this->financeId, 'location_id' => $this->north, 'is_global' => 0],
            ['user_id' => $this->opsId,     'location_id' => $this->north, 'is_global' => 0],
        ]);
    }

    private function ensureLocation(string $code, string $name): int
    {
        $r = Db::table('locations')->where('code', $code)->find();
        return $r ? (int)$r['id'] : (int)Db::table('locations')->insertGetId([
            'code' => $code, 'name' => $name, 'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------------
    // #1 — Export jobs are private to the requester.
    // ---------------------------------------------------------------------

    public function test_export_show_blocked_for_non_requester_even_with_audit_view(): void
    {
        // Admin generates an audit export.
        $this->loginAs('admin');
        $job = $this->request('POST', '/api/v1/exports', ['kind' => 'audit', 'filters' => []]);
        $jobId = (int)$this->json($job)['data']['id'];

        // finance has audit.view AND audit.export — but the export was made
        // by a different user, so it must NOT be readable.
        $this->forgetSession();
        $this->loginAs('finance');
        $show = $this->request('GET', "/api/v1/exports/{$jobId}");
        $this->assertStatus(403, $show);
        $dl = $this->request('GET', "/api/v1/exports/{$jobId}/download");
        $this->assertStatus(403, $dl);
    }

    public function test_export_show_allowed_for_requester(): void
    {
        $this->loginAs('finance');
        $job = $this->request('POST', '/api/v1/exports', ['kind' => 'audit', 'filters' => []]);
        $jobId = (int)$this->json($job)['data']['id'];
        $show = $this->request('GET', "/api/v1/exports/{$jobId}");
        $this->assertStatus(200, $show);
    }

    // ---------------------------------------------------------------------
    // #2 — budget_utilization CSV is scope-clipped.
    // ---------------------------------------------------------------------

    public function test_budget_utilization_export_scope_clipped(): void
    {
        // Two allocations in different scopes
        $cat = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'BUExpCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $period = (int)(Db::table('budget_periods')
            ->where('period_start', '2026-04-01')->where('period_end', '2026-04-30')->value('id'))
            ?: (int)Db::table('budget_periods')->insertGetId([
                'label' => '2026-04', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            ]);
        $hqAllocId = (int)Db::table('budget_allocations')->insertGetId([
            'category_id' => $cat, 'period_id' => $period, 'scope_type' => 'location',
            'location_id' => $this->hq, 'cap_amount' => '1234.00', 'status' => 'active',
        ]);
        $northAllocId = (int)Db::table('budget_allocations')->insertGetId([
            'category_id' => $cat, 'period_id' => $period, 'scope_type' => 'location',
            'location_id' => $this->north, 'cap_amount' => '4321.00', 'status' => 'active',
        ]);

        $this->loginAs('finance');
        $job = $this->request('POST', '/api/v1/exports', [
            'kind' => 'budget_utilization', 'filters' => [],
        ]);
        $this->assertStatus(200, $job);
        $jobId = (int)$this->json($job)['data']['id'];
        $dl = $this->request('GET', "/api/v1/exports/{$jobId}/download");
        $this->assertStatus(200, $dl);
        $body = (string)$dl->getContent();
        self::assertStringContainsString('4321.00', $body, 'NORTH allocation must be in finance export');
        self::assertStringNotContainsString('1234.00', $body, 'HQ allocation must NOT leak into finance export');
    }

    // ---------------------------------------------------------------------
    // #3 — Attendance correction reviewer must be in scope.
    // ---------------------------------------------------------------------

    public function test_attendance_correction_approve_blocked_outside_scope(): void
    {
        // Front desk records attendance at HQ then submits a correction.
        $fdId = (int)Db::table('users')->where('username', 'frontdesk')->value('id');
        $hqAttId = (int)Db::table('attendance_records')->insertGetId([
            'location_id' => $this->hq, 'recorded_by_user_id' => $fdId,
            'occurred_at' => '2026-04-15 10:00:00', 'status' => 'active',
            'member_name' => 'X',
        ]);
        $corrId = (int)Db::table('attendance_correction_requests')->insertGetId([
            'target_attendance_id' => $hqAttId,
            'requested_by_user_id' => $fdId,
            'location_id'          => $this->hq,
            'proposed_payload_json' => json_encode([]),
            'reason'               => 'fix the member name on this row please',
            'status'               => 'submitted',
        ]);
        // operations is pinned to NORTH only; HQ correction must be 403.
        $this->loginAs('operations');
        $res = $this->request('POST', "/api/v1/attendance/corrections/{$corrId}/approve",
            ['comment' => 'ok']);
        $this->assertStatus(403, $res);
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_attendance_correction_reject_blocked_outside_scope(): void
    {
        $fdId = (int)Db::table('users')->where('username', 'frontdesk')->value('id');
        $hqAttId = (int)Db::table('attendance_records')->insertGetId([
            'location_id' => $this->hq, 'recorded_by_user_id' => $fdId,
            'occurred_at' => '2026-04-15 11:00:00', 'status' => 'active', 'member_name' => 'Y',
        ]);
        $corrId = (int)Db::table('attendance_correction_requests')->insertGetId([
            'target_attendance_id' => $hqAttId,
            'requested_by_user_id' => $fdId,
            'location_id' => $this->hq,
            'proposed_payload_json' => json_encode([]),
            'reason' => 'fix the member name on this row please',
            'status' => 'submitted',
        ]);
        $this->loginAs('operations');
        $res = $this->request('POST', "/api/v1/attendance/corrections/{$corrId}/reject",
            ['comment' => 'Rejecting because of insufficient detail in reason']);
        $this->assertStatus(403, $res);
    }

    // ---------------------------------------------------------------------
    // #4 — Schedule adjustment reviewer must be in scope.
    // ---------------------------------------------------------------------

    public function test_schedule_adjustment_approve_blocked_outside_scope(): void
    {
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $entryId = (int)Db::table('schedule_entries')->insertGetId([
            'coach_user_id' => $coachId, 'location_id' => $this->hq,
            'starts_at' => '2026-05-01 09:00:00', 'ends_at' => '2026-05-01 10:00:00',
            'title' => 'HQ class', 'status' => 'active', 'created_by' => 1,
        ]);
        $adjId = (int)Db::table('schedule_adjustment_requests')->insertGetId([
            'target_entry_id' => $entryId,
            'requested_by_user_id' => $coachId,
            'proposed_changes_json' => json_encode(['starts_at' => '2026-05-01 10:00:00']),
            'reason' => 'venue issue forces us to push the start by an hour',
            'status' => 'submitted',
        ]);
        $this->loginAs('operations');
        $res = $this->request('POST', "/api/v1/schedule/adjustments/{$adjId}/approve",
            ['comment' => 'ok']);
        $this->assertStatus(403, $res);
    }

    public function test_schedule_adjustment_approve_blocked_when_target_moves_outside_scope(): void
    {
        // operations IS in scope for NORTH. Entry is at NORTH. But the
        // adjustment proposes moving it to HQ — out of operations' scope.
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $entryId = (int)Db::table('schedule_entries')->insertGetId([
            'coach_user_id' => $coachId, 'location_id' => $this->north,
            'starts_at' => '2026-05-02 09:00:00', 'ends_at' => '2026-05-02 10:00:00',
            'title' => 'NORTH class', 'status' => 'active', 'created_by' => 1,
        ]);
        $adjId = (int)Db::table('schedule_adjustment_requests')->insertGetId([
            'target_entry_id' => $entryId,
            'requested_by_user_id' => $coachId,
            'proposed_changes_json' => json_encode(['location_id' => $this->hq]),
            'reason' => 'space at NORTH unavailable; relocate to HQ for this slot',
            'status' => 'submitted',
        ]);
        $this->loginAs('operations');
        $res = $this->request('POST', "/api/v1/schedule/adjustments/{$adjId}/approve",
            ['comment' => 'ok']);
        $this->assertStatus(403, $res);
    }

    // ---------------------------------------------------------------------
    // #5 — Ledger scope filter via parent settlement → reimbursement.
    // ---------------------------------------------------------------------

    public function test_ledger_filters_by_scope_via_parent_settlement(): void
    {
        // Build an HQ-scoped reimbursement → settlement → ledger entries
        // and a NORTH-scoped pair, then verify finance (NORTH) sees only the
        // NORTH ledger lines.
        $hqRid = $this->seedReimbursement($this->hq);
        $northRid = $this->seedReimbursement($this->north);
        $hqSid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $hqRid, 'settlement_no' => 'S-LED-HQ-' . bin2hex(random_bytes(2)),
            'method' => 'cash', 'gross_amount' => '50.00', 'status' => 'confirmed',
            'recorded_by_user_id' => 1,
        ]);
        $northSid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $northRid, 'settlement_no' => 'S-LED-NO-' . bin2hex(random_bytes(2)),
            'method' => 'cash', 'gross_amount' => '50.00', 'status' => 'confirmed',
            'recorded_by_user_id' => 1,
        ]);
        Db::table('ledger_entries')->insertAll([
            ['ref_entity_type' => 'settlement', 'ref_entity_id' => $hqSid,
                'account_code' => 'reimbursement_payable', 'debit' => '50.00', 'credit' => '0.00',
                'memo' => 'hq-leak-marker', 'posted_by_user_id' => 1],
            ['ref_entity_type' => 'settlement', 'ref_entity_id' => $northSid,
                'account_code' => 'reimbursement_payable', 'debit' => '50.00', 'credit' => '0.00',
                'memo' => 'north-marker', 'posted_by_user_id' => 1],
        ]);

        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/ledger?size=200');
        $this->assertStatus(200, $res);
        $rows = $this->json($res)['data']['data'];
        $memos = array_column($rows, 'memo');
        self::assertContains('north-marker', $memos);
        self::assertNotContains('hq-leak-marker', $memos, 'finance must not see HQ ledger lines');
    }

    private function seedReimbursement(int $locationId): int
    {
        $catId = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'LedCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $period = (int)(Db::table('budget_periods')
            ->where('period_start', '2026-04-01')->where('period_end', '2026-04-30')->value('id'))
            ?: (int)Db::table('budget_periods')->insertGetId([
                'label' => '2026-04', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            ]);
        Db::table('budget_allocations')->insert([
            'category_id' => $catId, 'period_id' => $period, 'scope_type' => 'location',
            'location_id' => $locationId, 'cap_amount' => '1000.00', 'status' => 'active',
        ]);
        return (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no' => 'R-LED-' . bin2hex(random_bytes(3)),
            'submitter_user_id' => 1,
            'scope_location_id' => $locationId,
            'category_id' => $catId,
            'amount' => '50.00',
            'merchant' => 'M',
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
            'receipt_no' => 'RCPT-' . bin2hex(random_bytes(3)),
            'status' => 'settled',
        ]);
    }
}
