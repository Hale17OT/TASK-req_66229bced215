<?php
namespace Tests\api;

use app\service\security\FieldCipher;
use think\facade\Db;

/**
 * Confirms BLOCKER #7 — sensitive finance fields encrypted at rest, masked in
 * outputs, blind-indexed for equality lookup.
 */
class SensitiveFieldsApiTest extends ApiTestCase
{
    private function seedDraft(): array
    {
        $this->loginAs('admin');
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'EncCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $catId, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount'  => '1000.00',
        ]);
        $draft = $this->request('POST', '/api/v1/reimbursements', [
            'category_id'          => $catId,
            'amount'               => '12.34',
            'merchant'             => 'EncMerchant',
            'receipt_no'           => 'INV-PLAINTEXT-12345',
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
        ]);
        return [$catId, (int)$this->json($draft)['data']['id']];
    }

    public function test_receipt_no_stored_encrypted_at_rest(): void
    {
        [, $rid] = $this->seedDraft();
        $row = Db::table('reimbursements')->where('id', $rid)->find();
        $stored = (string)$row['receipt_no'];
        self::assertStringStartsWith(FieldCipher::PREFIX, $stored, 'receipt_no must be wrapped in enc:v1: envelope at rest');
        self::assertStringNotContainsString('INV-PLAINTEXT-12345', $stored);
        self::assertNotEmpty($row['receipt_no_hash'], 'blind-index column must be populated for lookups');
    }

    public function test_settlement_check_number_encrypted_at_rest(): void
    {
        $this->loginAs('admin');
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'EncSCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $catId, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount'  => '5000.00',
        ]);
        $draft = $this->request('POST', '/api/v1/reimbursements', [
            'category_id' => $catId, 'amount' => '50.00', 'merchant' => 'M',
            'receipt_no' => 'INV-CHK-' . bin2hex(random_bytes(2)),
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
        ]);
        $rid = (int)$this->json($draft)['data']['id'];
        // attach + submit + approve so settlement is allowed
        $path = sys_get_temp_dir() . '/sf-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->request('POST', "/api/v1/reimbursements/{$rid}/attachments", null,
            ['content-type' => 'multipart/form-data'],
            ['file' => ['name' => 'a.pdf', 'type' => 'application/pdf',
                'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)]]);
        $this->request('POST', "/api/v1/reimbursements/{$rid}/submit");
        $this->request('POST', "/api/v1/reimbursements/{$rid}/approve", ['comment' => 'ok']);
        $rec = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid, 'method' => 'check',
            'gross_amount' => '50.00', 'check_number' => 'CHK-PLAIN-9876',
        ]);
        $this->assertStatus(200, $rec);
        $sid = (int)$this->json($rec)['data']['id'];
        $stored = (string)Db::table('settlement_records')->where('id', $sid)->value('check_number');
        self::assertStringStartsWith(FieldCipher::PREFIX, $stored);
        self::assertStringNotContainsString('CHK-PLAIN-9876', $stored);
    }

    public function test_api_response_masks_receipt_for_non_unmask_user(): void
    {
        // Strip the unmask perm from finance for this test by pinning a role
        // baseline. Easier: exercise as 'frontdesk' which doesn't have it.
        [, $rid] = $this->seedDraft();
        // Make a frontdesk-owned reimbursement so frontdesk can read it back.
        $fdId = (int)Db::table('users')->where('username', 'frontdesk')->value('id');
        Db::table('reimbursements')->where('id', $rid)->update(['submitter_user_id' => $fdId]);
        $this->forgetSession();
        $this->loginAs('frontdesk');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}");
        $this->assertStatus(200, $res);
        $receipt = $this->json($res)['data']['receipt_no'];
        self::assertStringNotContainsString('INV-PLAINTEXT-12345', (string)$receipt);
        // FieldCipher::mask exposes the LAST FOUR chars only, padded with `*`.
        self::assertSame(str_repeat('*', strlen('INV-PLAINTEXT-12345') - 4) . '2345', $receipt,
            'receipt_no must be last-4 masked for non-unmask viewer');
    }

    public function test_audit_log_payload_masks_sensitive_fields(): void
    {
        [, $rid] = $this->seedDraft();
        $row = Db::table('audit_logs')
            ->where('action', 'reimbursement.created')
            ->where('target_entity_id', (string)$rid)
            ->order('id', 'desc')->find();
        self::assertNotEmpty($row);
        $after = is_string($row['after_json']) ? json_decode($row['after_json'], true) : $row['after_json'];
        self::assertArrayHasKey('receipt_no', $after);
        self::assertSame(str_repeat('*', strlen('INV-PLAINTEXT-12345') - 4) . '2345', $after['receipt_no'],
            'audit row must persist the MASKED receipt_no');
    }

    public function test_request_log_strips_sensitive_query_params(): void
    {
        // Trigger a request whose URL carries `password=` as a query param
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/audit?password=should-not-leak&action=auth');
        $this->assertStatus(200, $res);

        $logPath = '/var/www/html/runtime/log/' . date('Ymd') . '.log';
        $logRow  = '';
        if (file_exists($logPath)) {
            $logRow = (string)shell_exec('grep -F "/api/v1/audit" ' . escapeshellarg($logPath) . ' | tail -1');
        }
        self::assertStringNotContainsString('should-not-leak', $logRow, 'access log must strip sensitive query params');
    }
}
