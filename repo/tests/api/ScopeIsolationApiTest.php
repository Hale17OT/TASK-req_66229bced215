<?php
namespace Tests\api;

use think\facade\Db;

/**
 * BLOCKER #4 — list/read/export endpoints must filter consistently by data
 * scope, and direct reads of out-of-scope rows must return 403 (not 200 with
 * a row the caller shouldn't see).
 */
class ScopeIsolationApiTest extends ApiTestCase
{
    private int $hq;
    private int $north;
    private int $financeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq    = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $this->north = $this->ensureLocation('NORTH', 'North Studio');
        $this->financeId = (int)Db::table('users')->where('username', 'finance')->value('id');

        // Pin finance to NORTH only (no global scope). Other demo users left
        // alone so the per-test reset keeps them functional.
        Db::execute('DELETE FROM user_scope_assignments WHERE user_id = ?', [$this->financeId]);
        Db::table('user_scope_assignments')->insert([
            'user_id' => $this->financeId, 'location_id' => $this->north, 'is_global' => 0,
        ]);
    }

    private function ensureLocation(string $code, string $name): int
    {
        $row = Db::table('locations')->where('code', $code)->find();
        return $row ? (int)$row['id'] : (int)Db::table('locations')->insertGetId([
            'code' => $code, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function seedReimbursement(int $locationId, string $merchant = 'X'): int
    {
        $catId = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'SCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        // Reuse the period if one already exists for this date range — the
        // table's unique key on (period_start, period_end) means we cannot
        // blindly INSERT every time.
        $periodId = (int)(Db::table('budget_periods')
            ->where('period_start', '2026-04-01')->where('period_end', '2026-04-30')->value('id'))
            ?: (int)Db::table('budget_periods')->insertGetId([
                'label' => '2026-04', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            ]);
        Db::table('budget_allocations')->insert([
            'category_id' => $catId, 'period_id' => $periodId,
            'scope_type' => 'location', 'location_id' => $locationId,
            'cap_amount' => '5000.00', 'status' => 'active',
        ]);
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-SC-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1, // admin owns it
            'scope_location_id'    => $locationId,
            'category_id'          => $catId,
            'amount'               => '50.00',
            'merchant'             => $merchant,
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
            'receipt_no'           => 'RCPT-' . bin2hex(random_bytes(3)),
            'status'               => 'submitted',
        ]);
        Db::table('fund_commitments')->insert([
            'allocation_id' => (int)Db::table('budget_allocations')->where('category_id', $catId)->value('id'),
            'reimbursement_id' => $rid,
            'amount' => '50.00',
            'status' => 'active',
        ]);
        return $rid;
    }

    public function test_reimbursement_list_filters_by_scope(): void
    {
        $hqRid    = $this->seedReimbursement($this->hq, 'HQ-merchant');
        $northRid = $this->seedReimbursement($this->north, 'NORTH-merchant');

        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/reimbursements?size=50');
        $this->assertStatus(200, $res);
        $rows = $this->json($res)['data']['data'];
        $ids = array_column($rows, 'id');
        self::assertContains($northRid, $ids, 'finance must see NORTH row');
        self::assertNotContains($hqRid, $ids, 'finance must NOT see HQ row');
    }

    public function test_reimbursement_show_outside_scope_returns_403(): void
    {
        $hqRid = $this->seedReimbursement($this->hq);
        $this->loginAs('finance');
        $res = $this->request('GET', "/api/v1/reimbursements/{$hqRid}");
        $this->assertStatus(403, $res);
    }

    public function test_settlement_list_filters_by_parent_reimbursement_scope(): void
    {
        $hqRid = $this->seedReimbursement($this->hq);
        $northRid = $this->seedReimbursement($this->north);
        $hqSid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $hqRid, 'settlement_no' => 'S-A-' . bin2hex(random_bytes(3)),
            'method' => 'cash', 'gross_amount' => '50.00', 'status' => 'confirmed',
            'recorded_by_user_id' => 1,
        ]);
        $northSid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $northRid, 'settlement_no' => 'S-B-' . bin2hex(random_bytes(3)),
            'method' => 'cash', 'gross_amount' => '50.00', 'status' => 'confirmed',
            'recorded_by_user_id' => 1,
        ]);

        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/settlements?size=50');
        $this->assertStatus(200, $res);
        $ids = array_column($this->json($res)['data']['data'], 'id');
        self::assertContains($northSid, $ids);
        self::assertNotContains($hqSid, $ids);
    }

    public function test_settlement_show_outside_scope_returns_403(): void
    {
        $hqRid = $this->seedReimbursement($this->hq);
        $hqSid = (int)Db::table('settlement_records')->insertGetId([
            'reimbursement_id' => $hqRid, 'settlement_no' => 'S-O-' . bin2hex(random_bytes(3)),
            'method' => 'cash', 'gross_amount' => '50.00', 'status' => 'confirmed',
            'recorded_by_user_id' => 1,
        ]);
        $this->loginAs('finance');
        $res = $this->request('GET', "/api/v1/settlements/{$hqSid}");
        $this->assertStatus(403, $res);
    }

    public function test_budget_utilization_filters_by_scope(): void
    {
        // Seed allocations in each scope
        $cat = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'BU-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $per = (int)Db::table('budget_periods')->insertGetId([
            'label' => '2026-05', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
        ]);
        Db::table('budget_allocations')->insertAll([
            ['category_id' => $cat, 'period_id' => $per, 'scope_type' => 'location',
                'location_id' => $this->hq, 'cap_amount' => '1000.00', 'status' => 'active'],
            ['category_id' => $cat, 'period_id' => $per, 'scope_type' => 'location',
                'location_id' => $this->north, 'cap_amount' => '2000.00', 'status' => 'active'],
        ]);
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/budget/utilization');
        $this->assertStatus(200, $res);
        $rows = $this->json($res)['data'];
        $locs = array_column($rows, 'location_id');
        self::assertContains($this->north, $locs);
        self::assertNotContains($this->hq, $locs);
    }

    public function test_commitment_list_filters_by_scope(): void
    {
        $hqRid = $this->seedReimbursement($this->hq);
        $northRid = $this->seedReimbursement($this->north);
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/budget/commitments');
        $this->assertStatus(200, $res);
        $rows = $this->json($res)['data']['data'];
        $rids = array_unique(array_column($rows, 'reimbursement_id'));
        self::assertContains($northRid, $rids);
        self::assertNotContains($hqRid, $rids);
    }

    public function test_audit_search_filters_by_actor_for_non_global(): void
    {
        // Seed an audit row by another actor (admin id=1)
        Db::table('audit_logs')->insert([
            'occurred_at' => date('Y-m-d H:i:s.0'),
            'actor_user_id' => 1, 'actor_username' => 'admin',
            'action' => 'test.cross_actor', 'target_entity' => 'test', 'target_entity_id' => 'x',
            'outcome' => 'success', 'row_hash' => str_repeat('a', 64),
        ]);
        // finance has audit.view per FINANCE role baseline
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/audit?size=200');
        $this->assertStatus(200, $res);
        $rows = $this->json($res)['data']['data'];
        $actors = array_unique(array_column($rows, 'actor_user_id'));
        // Finance must not see admin's actor row
        self::assertNotContains(1, $actors, 'finance must not see admin-actor audit rows');
    }

    public function test_export_csv_scope_clipped(): void
    {
        $hqRid = $this->seedReimbursement($this->hq, 'HQ-AcmeMerchant');
        $northRid = $this->seedReimbursement($this->north, 'NORTH-OtherMerchant');
        $this->loginAs('finance');
        $job = $this->request('POST', '/api/v1/exports', [
            'kind' => 'reimbursements', 'filters' => [],
        ]);
        $this->assertStatus(200, $job);
        $jobId = (int)$this->json($job)['data']['id'];
        $dl = $this->request('GET', "/api/v1/exports/{$jobId}/download");
        $this->assertStatus(200, $dl);
        $body = (string)$dl->getContent();
        self::assertStringContainsString('NORTH-OtherMerchant', $body);
        self::assertStringNotContainsString('HQ-AcmeMerchant', $body, 'export must not leak out-of-scope rows');
    }
}
