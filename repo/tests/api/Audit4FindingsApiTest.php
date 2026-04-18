<?php
namespace Tests\api;

use think\facade\Db;

/**
 * audit-4 regression tests for the fourth-round findings.
 *
 *   #1 — front-desk attendance page endpoint mismatch is guarded by a
 *         static/source contract test in `tests/unit/AttendancePageEndpointContractTest.php`.
 *   #2 — settlement record path must enforce object/data-scope authz
 *         against the target reimbursement.
 *   #3 — reimbursement draft create/update/submit must validate
 *         caller-supplied `scope_location_id`/`scope_department_id`.
 *   #4 — budget precheck must reject out-of-scope (location, department)
 *         probes with 403 instead of leaking utilization data.
 *   #6 — reimbursement owner mutation endpoints must require the matching
 *         action permission in addition to ownership.
 *
 * Each test FAILS on the pre-patch code and PASSES after the fix.
 */
class Audit4FindingsApiTest extends ApiTestCase
{
    private int $hq;
    private int $north;
    private int $financeId;
    private int $frontdeskId;
    private int $coachId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq          = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $this->north       = $this->ensureLocation('NORTH', 'North Studio');
        $this->financeId   = (int)Db::table('users')->where('username', 'finance')->value('id');
        $this->frontdeskId = (int)Db::table('users')->where('username', 'frontdesk')->value('id');
        $this->coachId     = (int)Db::table('users')->where('username', 'coach')->value('id');

        // Pin finance to NORTH only (not global). Leave other demo users
        // alone so their per-test reset keeps them functional.
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

    /** Seed a category + org allocation and return the category id. */
    private function seedCategoryWithAllocation(string $cap = '5000.00'): int
    {
        $catId = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'A4Cat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $periodId = (int)(Db::table('budget_periods')
            ->where('period_start', '2026-04-01')->where('period_end', '2026-04-30')->value('id'))
            ?: (int)Db::table('budget_periods')->insertGetId([
                'label' => '2026-04', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            ]);
        Db::table('budget_allocations')->insert([
            'category_id' => $catId, 'period_id' => $periodId,
            'scope_type' => 'org', 'cap_amount' => $cap, 'status' => 'active',
        ]);
        return $catId;
    }

    /** Insert a draft directly and return its id (bypassing permission gates). */
    private function seedDraft(int $submitterId, int $categoryId, ?int $locationId, ?int $departmentId, ?string $receipt = null): int
    {
        return (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-A4-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => $submitterId,
            'scope_location_id'    => $locationId,
            'scope_department_id'  => $departmentId,
            'category_id'          => $categoryId,
            'amount'               => '25.00',
            'merchant'             => 'A4M',
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
            'receipt_no'           => $receipt ?? ('R-A4-' . bin2hex(random_bytes(3))),
            'status'               => 'draft',
        ]);
    }

    // ---------------------------------------------------------------------
    // HIGH #2 — settlement record requires scope on target reimbursement
    // ---------------------------------------------------------------------

    public function test_settlement_record_rejects_out_of_scope_reimbursement(): void
    {
        // A reimbursement owned by admin, scoped to HQ, already approved and
        // ready for settlement. Finance (pinned to NORTH) must not be able to
        // post a settlement against it even though they hold settlement.record.
        $catId = $this->seedCategoryWithAllocation();
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-A4S-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1, // admin
            'scope_location_id'    => $this->hq,
            'category_id'          => $catId,
            'amount'               => '50.00', 'merchant' => 'A4SM',
            'service_period_start' => '2026-04-15', 'service_period_end' => '2026-04-15',
            'receipt_no'           => 'A4S-' . bin2hex(random_bytes(3)),
            'status'               => 'settlement_pending',
        ]);

        $this->loginAs('finance');
        $res = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid,
            'method'           => 'cash',
            'gross_amount'     => '50.00',
        ]);
        $this->assertStatus(403, $res, 'settlement.record must be blocked for out-of-scope reimbursement');
        $this->assertEnvelopeCode(40300, $res);
        self::assertSame(
            0,
            Db::table('settlement_records')->where('reimbursement_id', $rid)->count(),
            'no settlement row should have been written'
        );
    }

    public function test_settlement_record_allowed_for_in_scope_reimbursement(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-A4S2-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => 1, // admin
            'scope_location_id'    => $this->north,
            'category_id'          => $catId,
            'amount'               => '25.00', 'merchant' => 'A4SMok',
            'service_period_start' => '2026-04-15', 'service_period_end' => '2026-04-15',
            'receipt_no'           => 'A4S2-' . bin2hex(random_bytes(3)),
            'status'               => 'settlement_pending',
        ]);

        $this->loginAs('finance');
        $res = $this->request('POST', '/api/v1/settlements', [
            'reimbursement_id' => $rid,
            'method'           => 'cash',
            'gross_amount'     => '25.00',
        ]);
        $this->assertStatus(200, $res, 'in-scope settlement.record must succeed');
        $this->assertEnvelopeCode(0, $res);
    }

    // ---------------------------------------------------------------------
    // HIGH #3 — reimbursement draft scope fields validated on write paths
    // ---------------------------------------------------------------------

    public function test_create_draft_rejects_out_of_scope_location(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $this->loginAs('finance'); // pinned to NORTH
        $res = $this->request('POST', '/api/v1/reimbursements', [
            'category_id'         => $catId,
            'amount'              => '10.00',
            'merchant'            => 'ScopeTest',
            'receipt_no'          => 'STa-' . bin2hex(random_bytes(3)),
            'service_period_start'=> '2026-04-15',
            'service_period_end'  => '2026-04-15',
            'scope_location_id'   => $this->hq, // NOT in finance's scope
        ]);
        $this->assertStatus(403, $res, 'draft create must reject out-of-scope location');
    }

    public function test_create_draft_allows_in_scope_location(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $this->loginAs('finance');
        $res = $this->request('POST', '/api/v1/reimbursements', [
            'category_id'         => $catId,
            'amount'              => '10.00',
            'merchant'            => 'ScopeTestOk',
            'receipt_no'          => 'STb-' . bin2hex(random_bytes(3)),
            'service_period_start'=> '2026-04-15',
            'service_period_end'  => '2026-04-15',
            'scope_location_id'   => $this->north, // IS in finance's scope
        ]);
        $this->assertStatus(200, $res);
        self::assertSame($this->north, (int)$this->json($res)['data']['scope_location_id']);
    }

    public function test_update_draft_rejects_move_to_out_of_scope_location(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($this->financeId, $catId, $this->north, null);
        $this->loginAs('finance');
        $res = $this->request('PUT', "/api/v1/reimbursements/{$rid}", [
            'scope_location_id' => $this->hq,
            'version'           => 1,
        ]);
        $this->assertStatus(403, $res, 'update must reject moving a draft into an out-of-scope location');
    }

    public function test_update_draft_allowed_for_in_scope_change(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($this->financeId, $catId, $this->north, null);
        $this->loginAs('finance');
        $res = $this->request('PUT', "/api/v1/reimbursements/{$rid}", [
            'merchant' => 'Renamed',
            'version'  => 1,
        ]);
        $this->assertStatus(200, $res);
    }

    public function test_submit_rejects_draft_with_out_of_scope_assignment(): void
    {
        // Draft written directly (bypassing service-layer validation) with a
        // scope the caller does not hold — submit must refuse to advance it.
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($this->financeId, $catId, $this->hq, null);
        // Attach something so the attachment gate isn't the failure reason.
        Db::table('reimbursement_attachments')->insert([
            'reimbursement_id' => $rid,
            'uploaded_by_user_id' => $this->financeId,
            'file_name'  => 'a.pdf',
            'mime_type'  => 'application/pdf',
            'size_bytes' => 10,
            'storage_path' => 'x',
            'sha256'     => str_repeat('a', 64),
        ]);
        $this->loginAs('finance');
        $res = $this->request('POST', "/api/v1/reimbursements/{$rid}/submit");
        $this->assertStatus(403, $res, 'submit must reject draft with out-of-scope assignment');
    }

    // ---------------------------------------------------------------------
    // HIGH #4 — budget precheck scope leakage
    // ---------------------------------------------------------------------

    public function test_precheck_rejects_out_of_scope_location(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $this->loginAs('finance'); // pinned to NORTH
        $res = $this->request('GET',
            "/api/v1/budget/precheck?category_id={$catId}&location_id={$this->hq}&service_start=2026-04-15&amount=1.00"
        );
        $this->assertStatus(403, $res, 'precheck must refuse out-of-scope location probe');
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_precheck_allows_in_scope_location(): void
    {
        $catId = $this->seedCategoryWithAllocation();
        $this->loginAs('finance');
        $res = $this->request('GET',
            "/api/v1/budget/precheck?category_id={$catId}&location_id={$this->north}&service_start=2026-04-15&amount=1.00"
        );
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_precheck_allows_unscoped_probe_for_global_user(): void
    {
        // Unscoped probes (no location_id/department_id) are legitimate —
        // they hit the org-wide allocation. Admin is global so this should
        // always succeed.
        $catId = $this->seedCategoryWithAllocation();
        $this->loginAs('admin');
        $res = $this->request('GET',
            "/api/v1/budget/precheck?category_id={$catId}&service_start=2026-04-15&amount=1.00"
        );
        $this->assertStatus(200, $res);
    }

    // ---------------------------------------------------------------------
    // MEDIUM #6 — explicit owner-action permissions
    // ---------------------------------------------------------------------

    /**
     * Build a custom role that owns a reimbursement row but does NOT carry
     * `reimbursement.edit_own_draft` / `reimbursement.submit`. We expect the
     * owner mutation endpoints to refuse these callers even though ownership
     * matches, because the RBAC permission is now checked in addition to
     * ownership.
     */
    private function userWithoutOwnerPerms(): int
    {
        $roleKey = 'NoOwnerPerms_' . bin2hex(random_bytes(2));
        $roleId = (int)Db::table('roles')->insertGetId([
            'key' => $roleKey, 'name' => $roleKey,
            'description' => 'Owns rows but lacks edit_own_draft/submit',
            'is_system' => 0,
        ]);
        // Grant only reimbursement.create so they can even reach the listing.
        $createPerm = (int)Db::table('permissions')->where('key', 'reimbursement.create')->value('id');
        Db::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $createPerm]);

        $uname = 'noperm-' . bin2hex(random_bytes(2));
        $hash = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
        $uid = (int)Db::table('users')->insertGetId([
            'username' => $uname, 'display_name' => 'NoPerm User',
            'password_hash' => $hash, 'status' => 'active',
            'must_change_password' => 0, 'failed_login_count' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        Db::table('user_roles')->insert(['user_id' => $uid, 'role_id' => $roleId]);
        Db::table('user_scope_assignments')->insert([
            'user_id' => $uid, 'location_id' => $this->hq, 'is_global' => 0,
        ]);
        // Stash the username so loginAs works.
        return $uid;
    }

    public function test_owner_update_draft_requires_edit_own_draft_permission(): void
    {
        $uid = $this->userWithoutOwnerPerms();
        $uname = (string)Db::table('users')->where('id', $uid)->value('username');
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($uid, $catId, $this->hq, null);

        $this->loginAs($uname);
        $res = $this->request('PUT', "/api/v1/reimbursements/{$rid}", [
            'merchant' => 'ShouldBeBlocked', 'version' => 1,
        ]);
        $this->assertStatus(403, $res, 'owner without edit_own_draft must not update');
    }

    public function test_owner_submit_requires_submit_permission(): void
    {
        $uid = $this->userWithoutOwnerPerms();
        $uname = (string)Db::table('users')->where('id', $uid)->value('username');
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($uid, $catId, $this->hq, null);

        $this->loginAs($uname);
        $res = $this->request('POST', "/api/v1/reimbursements/{$rid}/submit");
        $this->assertStatus(403, $res, 'owner without reimbursement.submit must not submit');
    }

    public function test_owner_withdraw_requires_submit_permission(): void
    {
        $uid = $this->userWithoutOwnerPerms();
        $uname = (string)Db::table('users')->where('id', $uid)->value('username');
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($uid, $catId, $this->hq, null);
        // Park the row in a withdrawable state directly.
        Db::table('reimbursements')->where('id', $rid)->update(['status' => 'submitted']);

        $this->loginAs($uname);
        $res = $this->request('POST', "/api/v1/reimbursements/{$rid}/withdraw");
        $this->assertStatus(403, $res, 'owner without reimbursement.submit must not withdraw');
    }

    public function test_owner_with_correct_permissions_can_submit(): void
    {
        // frontdesk role DOES have edit_own_draft + submit (per §11.2 matrix).
        $catId = $this->seedCategoryWithAllocation();
        $rid = $this->seedDraft($this->frontdeskId, $catId, $this->hq, null);
        Db::table('reimbursement_attachments')->insert([
            'reimbursement_id' => $rid,
            'uploaded_by_user_id' => $this->frontdeskId,
            'file_name'  => 'a.pdf',
            'mime_type'  => 'application/pdf',
            'size_bytes' => 10,
            'storage_path' => 'x',
            'sha256'     => str_repeat('b', 64),
        ]);

        $this->loginAs('frontdesk');
        $res = $this->request('POST', "/api/v1/reimbursements/{$rid}/submit");
        $this->assertStatus(200, $res, 'owner holding reimbursement.submit must be able to submit');
    }
}
