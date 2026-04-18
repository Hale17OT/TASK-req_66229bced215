<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Covers every /api/v1/settlements route.
 */
class SettlementApiTest extends ApiTestCase
{
    /** Build an approved-but-unsettled reimbursement ready for settlement. */
    private function approvedReimbursement(string $amount = '100.00'): int
    {
        $this->loginAs('admin');
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'SetCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $catId, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount'  => '50000.00',
        ]);
        $draft = $this->request('POST', '/api/v1/reimbursements', [
            'category_id' => $catId, 'amount' => $amount, 'merchant' => 'S-M',
            'receipt_no'  => 'S-' . bin2hex(random_bytes(3)),
            'service_period_start' => '2026-04-15', 'service_period_end' => '2026-04-15',
        ]);
        $rid = (int)$this->json($draft)['data']['id'];
        // attach
        $path = sys_get_temp_dir() . '/sett-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->request(
            'POST', "/api/v1/reimbursements/{$rid}/attachments", null,
            ['content-type' => 'multipart/form-data'],
            ['file' => ['name' => 'a.pdf', 'type' => 'application/pdf',
                'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)]]
        );
        // submit + approve
        $this->request('POST', "/api/v1/reimbursements/{$rid}/submit");
        $this->request('POST', "/api/v1/reimbursements/{$rid}/approve", ['comment' => 'ok']);
        return $rid;
    }

    public function test_index_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/settlements');
        $this->assertStatus(401, $res);
    }

    public function test_index_returns_list(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/settlements');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_record_rejects_invalid_method(): void
    {
        $rid = $this->approvedReimbursement();
        $res = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid,
            'method'           => 'crypto',
            'gross_amount'     => '100.00',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_record_check_requires_check_number(): void
    {
        $rid = $this->approvedReimbursement();
        $res = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid,
            'method'           => 'check',
            'gross_amount'     => '100.00',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_record_creates_and_show_fetches(): void
    {
        $rid = $this->approvedReimbursement();
        $rec = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid, 'method' => 'cash',
            'gross_amount'     => '100.00',
            'cash_receipt_ref' => 'CR-1',
        ]);
        $this->assertStatus(200, $rec);
        $sid = (int)$this->json($rec)['data']['id'];
        self::assertSame('recorded_not_confirmed', $this->json($rec)['data']['status']);

        $show = $this->request('GET', "/api/v1/settlements/{$sid}");
        $this->assertStatus(200, $show);
        self::assertSame($sid, $this->json($show)['data']['id']);
    }

    public function test_confirm_posts_ledger(): void
    {
        $rid = $this->approvedReimbursement();
        $rec = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid, 'method' => 'cash',
            'gross_amount'     => '100.00',
        ]);
        $sid = (int)$this->json($rec)['data']['id'];
        $conf = $this->request('POST', "/api/v1/settlements/{$sid}/confirm");
        $this->assertStatus(200, $conf);
        self::assertSame('confirmed', $this->json($conf)['data']['status']);
        self::assertGreaterThan(0, Db::table('ledger_entries')
            ->where('ref_entity_type', 'settlement')->where('ref_entity_id', $sid)->count());
    }

    public function test_refund_enforces_cumulative_cap(): void
    {
        $rid = $this->approvedReimbursement();
        $rec = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid, 'method' => 'cash', 'gross_amount' => '100.00',
        ]);
        $sid = (int)$this->json($rec)['data']['id'];
        $this->request('POST', "/api/v1/settlements/{$sid}/confirm");

        // Refund $60 ok
        $ok = $this->request('POST', "/api/v1/settlements/{$sid}/refund", [
            'amount' => '60.00', 'reason' => 'partial refund for incorrect service',
        ]);
        $this->assertStatus(200, $ok);

        // Refund another $50 would exceed the $100 cap
        $bad = $this->request('POST', "/api/v1/settlements/{$sid}/refund", [
            'amount' => '50.00', 'reason' => 'this would exceed',
        ]);
        $this->assertStatus(422, $bad);
    }

    public function test_mark_exception_transitions_state(): void
    {
        $rid = $this->approvedReimbursement();
        $rec = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid, 'method' => 'cash', 'gross_amount' => '100.00',
        ]);
        $sid = (int)$this->json($rec)['data']['id'];
        $res = $this->request('POST', "/api/v1/settlements/{$sid}/exception", [
            'reason' => 'Could not reconcile with batch report',
        ]);
        $this->assertStatus(200, $res);
        self::assertSame('exception', $this->json($res)['data']['status']);
    }
}
