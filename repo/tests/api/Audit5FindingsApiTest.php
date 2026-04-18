<?php
namespace Tests\api;

use think\facade\Db;

/**
 * audit-5 regression tests for the fifth-round findings.
 *
 *   #1 — reconciliation list/start must be scope-aware; non-global callers
 *         see only their own runs and exceptions clipped to their scope.
 *   #2 — idempotency middleware must persist a terminal response even when
 *         the downstream call throws, so same-key retries replay instead of
 *         hitting the `in_flight` 409.
 *   #3 — reimbursements must expose a pre-submit duplicate probe endpoint
 *         the UI can call for immediate feedback.
 *   #4 — (this file) regression coverage for scope isolation on the
 *         reconciliation endpoints.
 */
class Audit5FindingsApiTest extends ApiTestCase
{
    private int $hq;
    private int $north;
    private int $financeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq        = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $this->north     = $this->ensureLocation('NORTH', 'North Studio');
        $this->financeId = (int)Db::table('users')->where('username', 'finance')->value('id');

        // Pin finance to NORTH only (not global) so scope isolation is
        // testable; admin stays global.
        Db::execute('DELETE FROM user_scope_assignments WHERE user_id = ?', [$this->financeId]);
        Db::table('user_scope_assignments')->insert([
            'user_id' => $this->financeId, 'location_id' => $this->north, 'is_global' => 0,
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
    // HIGH #1 + MEDIUM #4 — reconciliation scope isolation
    // ---------------------------------------------------------------------

    /**
     * Seed an approved-but-unsettled reimbursement in a given scope so the
     * reconciliation scan will surface it as an `unsettled_approved`
     * exception unless the caller's scope excludes it.
     */
    private function seedUnsettledApproved(int $locationId, string $merchantSuffix): int
    {
        $catId = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'RBCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
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
            'reimbursement_no'     => 'R-A5R-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1, // admin
            'scope_location_id'    => $locationId,
            'category_id'          => $catId,
            'amount'               => '50.00',
            'merchant'             => 'A5Merchant-' . $merchantSuffix,
            'service_period_start' => '2026-04-10',
            'service_period_end'   => '2026-04-10',
            'receipt_no'           => 'A5R-' . bin2hex(random_bytes(3)),
            'status'               => 'settlement_pending',
            'decided_at'           => '2026-04-10 10:00:00',
        ]);
    }

    public function test_reconciliation_start_scope_clips_exceptions_for_non_global_caller(): void
    {
        $hqRid    = $this->seedUnsettledApproved($this->hq,    'HQ');
        $northRid = $this->seedUnsettledApproved($this->north, 'NORTH');

        $this->loginAs('finance'); // pinned to NORTH
        $res = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
        ]);
        $this->assertStatus(200, $res);
        $runId = (int)$this->json($res)['data']['id'];

        $exceptionRows = Db::table('reconciliation_exceptions')
            ->where('run_id', $runId)
            ->column('reimbursement_id');
        self::assertContains($northRid, $exceptionRows, 'NORTH unsettled row must surface');
        self::assertNotContains($hqRid, $exceptionRows,
            'HQ unsettled row must NOT leak into a NORTH-pinned operator\'s run');
    }

    public function test_reconciliation_start_scope_not_applied_for_global_caller(): void
    {
        $hqRid    = $this->seedUnsettledApproved($this->hq,    'HQ-G');
        $northRid = $this->seedUnsettledApproved($this->north, 'NORTH-G');

        $this->loginAs('admin'); // global
        $res = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
        ]);
        $this->assertStatus(200, $res);
        $runId = (int)$this->json($res)['data']['id'];

        $rows = Db::table('reconciliation_exceptions')
            ->where('run_id', $runId)
            ->column('reimbursement_id');
        self::assertContains($hqRid, $rows);
        self::assertContains($northRid, $rows);
    }

    public function test_reconciliation_index_filters_by_starter_for_non_global_caller(): void
    {
        // Admin starts one run.
        $this->loginAs('admin');
        $adminRun = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        ]);
        $adminRunId = (int)$this->json($adminRun)['data']['id'];
        $this->forgetSession();

        // Finance starts a run of their own.
        $this->loginAs('finance');
        $financeRun = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        ]);
        $financeRunId = (int)$this->json($financeRun)['data']['id'];

        // Finance's index must include their own run but exclude admin's.
        $res = $this->request('GET', '/api/v1/reconciliation/runs');
        $this->assertStatus(200, $res);
        $ids = array_column($this->json($res)['data']['data'], 'id');
        self::assertContains($financeRunId, $ids);
        self::assertNotContains($adminRunId, $ids,
            'non-global caller must not see reconciliation runs they did not start');
    }

    public function test_reconciliation_index_global_sees_everything(): void
    {
        // Finance starts one.
        $this->loginAs('finance');
        $financeRun = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        ]);
        $financeRunId = (int)$this->json($financeRun)['data']['id'];
        $this->forgetSession();

        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/reconciliation/runs');
        $ids = array_column($this->json($res)['data']['data'], 'id');
        self::assertContains($financeRunId, $ids, 'admin must see all runs including finance-started');
    }

    // ---------------------------------------------------------------------
    // HIGH #2 — idempotency terminal-state persistence on exception
    // ---------------------------------------------------------------------

    public function test_idempotency_failed_request_replays_same_error_on_retry(): void
    {
        $this->loginAs('admin');
        // Fire a request that will deterministically fail inside the
        // downstream controller (unknown reimbursement id → 404 from the
        // service) but still runs under the idempotency middleware.
        $key = 'idem-fail-' . bin2hex(random_bytes(6));
        $body = [
            'reimbursement_id' => 99999999,
            'method'           => 'cash',
            'gross_amount'     => '10.00',
        ];
        $first = $this->request('POST', '/api/v1/settlements', $body, [
            'idempotency-key' => $key,
        ]);
        self::assertTrue(in_array($first->getCode(), [404, 422, 403], true),
            'first call must surface the downstream error (got ' . $first->getCode() . ')');

        // A DB row should have been written and marked completed — not
        // stuck in_flight — so the retry replays deterministically.
        $idemRow = Db::table('idempotency_keys')->where('key', $key)->find();
        self::assertNotEmpty($idemRow, 'idempotency row missing after failed call');
        self::assertSame('completed', $idemRow['state'],
            'failed call must terminate the idempotency row, not leave it in_flight');

        // Retry with same key → replay, same status, X-Idempotent-Replay header.
        $retry = $this->request('POST', '/api/v1/settlements', $body, [
            'idempotency-key' => $key,
        ]);
        self::assertSame($first->getCode(), $retry->getCode(),
            'same-key retry must replay the terminal response');
        self::assertSame(
            (string)$first->getContent(),
            (string)$retry->getContent(),
            'replay body must match the original terminal response byte-for-byte'
        );
        self::assertSame('1', $retry->getHeader('X-Idempotent-Replay'),
            'replay must carry the X-Idempotent-Replay flag');
    }

    // ---------------------------------------------------------------------
    // MEDIUM #3 — pre-submit duplicate probe endpoint
    // ---------------------------------------------------------------------

    public function test_duplicate_check_returns_ok_when_no_conflict(): void
    {
        $this->loginAs('admin');
        $res = $this->request(
            'GET',
            '/api/v1/reimbursements/duplicate-check'
            . '?merchant=' . urlencode('Pristine Supplier')
            . '&receipt_no=' . urlencode('FRESH-' . bin2hex(random_bytes(3)))
            . '&amount=10.00&service_period_start=2026-04-10&service_period_end=2026-04-10'
        );
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertTrue(($body['data']['ok'] ?? null) === true);
    }

    public function test_duplicate_check_flags_reserved_receipt(): void
    {
        // Seed a reserved duplicate_document_registry row directly so the
        // probe has something to clash against.
        $cat = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'DupCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-DUP-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1,
            'scope_location_id'    => $this->hq,
            'category_id'          => $cat,
            'amount'               => '50.00',
            'merchant'             => 'ConflictCo',
            'service_period_start' => '2026-04-10',
            'service_period_end'   => '2026-04-10',
            'receipt_no'           => 'CONFLICT-001',
            'status'               => 'submitted',
        ]);
        // DuplicateDocument uses blind indexes — mirror production by going
        // through the same DuplicateRegistry the controller uses so the
        // HMAC key is guaranteed to match (both paths resolve the cipher
        // through FieldCipher::fromEnv → the test-env fallback key).
        $registry = app()->make(\app\service\reimbursement\DuplicateRegistry::class);
        $nm = $registry->merchantBlindIndex('ConflictCo');
        $nr = $registry->receiptNoBlindIndex('CONFLICT-001');
        Db::table('duplicate_document_registry')->insert([
            'reimbursement_id'      => $rid,
            'normalized_merchant'   => $nm,
            'normalized_receipt_no' => $nr,
            'amount'                => '50.00',
            'service_period_start'  => '2026-04-10',
            'service_period_end'    => '2026-04-10',
            'state'                 => 'reserved',
        ]);

        $this->loginAs('admin');
        $res = $this->request(
            'GET',
            '/api/v1/reimbursements/duplicate-check'
            . '?merchant=' . urlencode('ConflictCo')
            . '&receipt_no=' . urlencode('CONFLICT-001')
            . '&amount=50.00&service_period_start=2026-04-10&service_period_end=2026-04-10'
        );
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertFalse(($body['data']['ok'] ?? null) === true);
        self::assertSame('receipt_reserved', $body['data']['reason']);
        self::assertSame($rid, (int)$body['data']['conflict_reimbursement_id']);
    }

    public function test_duplicate_check_requires_core_fields(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/reimbursements/duplicate-check');
        $this->assertStatus(422, $res);
    }

    public function test_duplicate_check_requires_reimbursement_permission(): void
    {
        // coach has `reimbursement.create` per baseline matrix, so use a
        // role without it. operations has `reimbursement.review` which is
        // also allowed — use a user whose permissions exclude both. The
        // frontdesk role has create so it's allowed; we need something
        // stricter. Build a disposable user with no reimbursement perms.
        $roleKey = 'NoProbe_' . bin2hex(random_bytes(2));
        $roleId = (int)Db::table('roles')->insertGetId([
            'key' => $roleKey, 'name' => $roleKey,
            'description' => 'no reimbursement perms', 'is_system' => 0,
        ]);
        $uname = 'noprobe-' . bin2hex(random_bytes(2));
        $hash = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
        $uid = (int)Db::table('users')->insertGetId([
            'username' => $uname, 'display_name' => 'NoProbe',
            'password_hash' => $hash, 'status' => 'active',
            'must_change_password' => 0, 'failed_login_count' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        Db::table('user_roles')->insert(['user_id' => $uid, 'role_id' => $roleId]);
        Db::table('user_scope_assignments')->insert([
            'user_id' => $uid, 'location_id' => $this->hq, 'is_global' => 0,
        ]);
        $this->loginAs($uname);
        $res = $this->request(
            'GET',
            '/api/v1/reimbursements/duplicate-check'
            . '?merchant=' . urlencode('X') . '&receipt_no=Y&amount=1'
        );
        $this->assertStatus(403, $res);
    }

    // ---------------------------------------------------------------------
    // MEDIUM #3 follow-up — attachment_count in show() so the UI can enforce
    // the per-reimbursement cap without an extra round trip.
    // ---------------------------------------------------------------------

    public function test_show_returns_attachment_count_zero_for_fresh_draft(): void
    {
        $cat = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'AttCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-ATT-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1,
            'scope_location_id'    => $this->hq,
            'category_id'          => $cat,
            'amount'               => '50.00',
            'merchant'             => 'AttMerch',
            'service_period_start' => '2026-04-10',
            'service_period_end'   => '2026-04-10',
            'receipt_no'           => 'ATT-' . bin2hex(random_bytes(3)),
            'status'               => 'draft',
        ]);
        $this->loginAs('admin');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}");
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('attachment_count', $body['data'],
            'show() must publish attachment_count so the UI can honor the per-reimbursement cap');
        self::assertSame(0, (int)$body['data']['attachment_count']);
    }

    public function test_show_counts_only_non_deleted_attachments(): void
    {
        $cat = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'AttCat2-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-ATT2-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1,
            'scope_location_id'    => $this->hq,
            'category_id'          => $cat,
            'amount'               => '50.00',
            'merchant'             => 'AttMerch2',
            'service_period_start' => '2026-04-10',
            'service_period_end'   => '2026-04-10',
            'receipt_no'           => 'ATT2-' . bin2hex(random_bytes(3)),
            'status'               => 'draft',
        ]);
        // Seed two live attachments + one soft-deleted one; the count must
        // report 2, not 3. Individual inserts because insertAll() requires
        // every row to carry the same column set.
        $base = [
            'reimbursement_id'    => $rid,
            'uploaded_by_user_id' => 1,
            'mime_type'           => 'application/pdf',
            'size_bytes'          => 10,
            'storage_path'        => 'x',
        ];
        Db::table('reimbursement_attachments')->insert(
            ['file_name' => 'a.pdf', 'sha256' => str_repeat('a', 64)] + $base
        );
        Db::table('reimbursement_attachments')->insert(
            ['file_name' => 'b.pdf', 'sha256' => str_repeat('b', 64)] + $base
        );
        Db::table('reimbursement_attachments')->insert(
            ['file_name' => 'c.pdf', 'sha256' => str_repeat('c', 64),
             'deleted_at' => '2026-04-12 00:00:00'] + $base
        );
        $this->loginAs('admin');
        $res = $this->request('GET', "/api/v1/reimbursements/{$rid}");
        $this->assertStatus(200, $res);
        self::assertSame(2, (int)$this->json($res)['data']['attachment_count']);
    }
}
