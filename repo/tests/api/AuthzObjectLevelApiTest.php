<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Object-level authorization regression tests for the audit-flagged
 * BLOCKER findings (#1 #2 #3).
 *
 * These tests must FAIL on the pre-patch code (where show/history return rows
 * to anyone authenticated) and PASS on the patched code (where each detail
 * fetch goes through Authorization::assertCanView*).
 */
class AuthzObjectLevelApiTest extends ApiTestCase
{
    /** Replace the seeded admin's global scope with location HQ so we can
     *  deliberately make finance/operations not-global for these tests. */
    protected function setUp(): void
    {
        parent::setUp();
        // Wipe scope rows for non-admin demo users and re-pin them to HQ
        // so we have predictable cross-scope behavior.
        $hq = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $other = $this->ensureSecondaryLocation();
        $userIds = Db::table('users')->whereIn('username', ['frontdesk', 'coach', 'finance', 'operations'])->column('id', 'username');
        Db::execute('DELETE FROM user_scope_assignments WHERE user_id IN (' . implode(',', $userIds) . ')');
        foreach ($userIds as $name => $uid) {
            $loc = $name === 'finance' ? $other : $hq;
            Db::table('user_scope_assignments')->insert([
                'user_id' => $uid, 'location_id' => $loc, 'is_global' => 0,
            ]);
        }
    }

    private function ensureSecondaryLocation(): int
    {
        $row = Db::table('locations')->where('code', 'NORTH')->find();
        if ($row) return (int)$row['id'];
        return (int)Db::table('locations')->insertGetId([
            'code' => 'NORTH', 'name' => 'North Studio', 'status' => 'active',
        ]);
    }

    private function adminCreatesReimbursementForUser(int $submitterId, ?int $locationId = null): int
    {
        $hq = $locationId ?? (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $catId = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'AuthzCat-' . bin2hex(random_bytes(2)),
            'status' => 'active',
        ]);
        $periodId = (int)(Db::table('budget_periods')
            ->where('period_start', '2026-04-01')->where('period_end', '2026-04-30')->value('id'))
            ?: (int)Db::table('budget_periods')->insertGetId([
                'label' => '2026-04', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            ]);
        Db::table('budget_allocations')->insert([
            'category_id' => $catId, 'period_id' => $periodId,
            'scope_type' => 'org', 'cap_amount' => '5000.00', 'status' => 'active',
        ]);
        return (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-AZT-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => $submitterId,
            'scope_location_id'    => $hq,
            'category_id'          => $catId,
            'amount'               => '50.00',
            'merchant'             => 'TestMerchant',
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
            'receipt_no'           => 'RCPT-' . bin2hex(random_bytes(3)),
            'status'               => 'submitted',
        ]);
    }

    // --------------------------------------------------------------
    // BLOCKER #1: audit endpoint authorization
    // --------------------------------------------------------------

    public function test_audit_search_rejects_user_without_audit_view(): void
    {
        // 'coach' demo role does NOT include audit.view
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/audit');
        $this->assertStatus(403, $res);
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_audit_search_allows_admin(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/audit');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_audit_export_requires_audit_export_perm(): void
    {
        $this->loginAs('coach');
        $res = $this->request('POST', '/api/v1/exports', [
            'kind' => 'audit', 'filters' => [],
        ]);
        $this->assertStatus(403, $res);
    }

    // --------------------------------------------------------------
    // BLOCKER #2: reimbursement object-level authorization
    // --------------------------------------------------------------

    public function test_reimbursement_show_blocked_for_unrelated_user(): void
    {
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $rid = $this->adminCreatesReimbursementForUser($coachId);

        // 'frontdesk' is not the submitter, has no review perm, and doesn't
        // share scope ownership for this reimbursement → must be 403.
        $this->loginAs('frontdesk');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}");
        $this->assertStatus(403, $res);
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_reimbursement_show_allowed_for_submitter(): void
    {
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $rid = $this->adminCreatesReimbursementForUser($coachId);
        $this->loginAs('coach');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}");
        $this->assertStatus(200, $res);
    }

    public function test_reimbursement_history_blocked_for_unrelated_user(): void
    {
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $rid = $this->adminCreatesReimbursementForUser($coachId);
        $this->loginAs('frontdesk');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}/history");
        $this->assertStatus(403, $res);
    }

    public function test_reimbursement_show_blocked_for_finance_outside_scope(): void
    {
        // finance was pinned to NORTH; create a reimbursement scoped to HQ.
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $rid = $this->adminCreatesReimbursementForUser($coachId);
        $this->loginAs('finance');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}");
        $this->assertStatus(403, $res);
    }

    // --------------------------------------------------------------
    // BLOCKER #3: settlement object-level authorization
    // --------------------------------------------------------------

    public function test_settlement_show_blocked_for_unrelated_user(): void
    {
        // Build an approved → settlement_pending reimbursement and a
        // settlement row, then verify a non-finance non-admin user gets 403
        // on the settlement detail.
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $rid = $this->adminCreatesReimbursementForUser($coachId);
        Db::table('reimbursements')->where('id', $rid)->update(['status' => 'settlement_pending']);
        $sid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $rid,
            'settlement_no'    => 'S-AZT-' . bin2hex(random_bytes(3)),
            'method'           => 'cash',
            'gross_amount'     => '50.00',
            'status'           => 'recorded_not_confirmed',
            'recorded_by_user_id' => 1,
        ]);
        $this->loginAs('coach'); // submitter, but no settlement perm
        $res = $this->request('GET', "/api/v1/settlements/{$sid}");
        $this->assertStatus(403, $res);
    }

    public function test_settlement_show_allowed_for_admin(): void
    {
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $rid = $this->adminCreatesReimbursementForUser($coachId);
        Db::table('reimbursements')->where('id', $rid)->update(['status' => 'settlement_pending']);
        $sid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $rid,
            'settlement_no'    => 'S-AZT2-' . bin2hex(random_bytes(3)),
            'method'           => 'cash',
            'gross_amount'     => '50.00',
            'status'           => 'recorded_not_confirmed',
            'recorded_by_user_id' => 1,
        ]);
        $this->loginAs('admin');
        $res = $this->request('GET', "/api/v1/settlements/{$sid}");
        $this->assertStatus(200, $res);
    }
}
