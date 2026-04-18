<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Regression tests for the third-round audit findings.
 *   #1 — admin location/department list endpoints require auth.manage_users
 *   #2 — schedule adjustment reviewer list is scope-clipped
 *   #3 — budget allocation index is scope-clipped
 *   #5 — role/user permission/scope updates carry structured before/after audit
 */
class Audit3FindingsApiTest extends ApiTestCase
{
    private int $hq;
    private int $north;
    private int $financeId;
    private int $opsId;
    private int $coachId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hq    = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        $this->north = $this->ensureLocation('NORTH', 'North Studio');
        $this->financeId = (int)Db::table('users')->where('username', 'finance')->value('id');
        $this->opsId     = (int)Db::table('users')->where('username', 'operations')->value('id');
        $this->coachId   = (int)Db::table('users')->where('username', 'coach')->value('id');

        // Finance + Operations pinned to NORTH only (not global).
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
    // HIGH #1 — admin reference endpoints require auth.manage_users
    // ---------------------------------------------------------------------

    public function test_admin_locations_index_requires_manage_users(): void
    {
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/admin/locations');
        $this->assertStatus(403, $res);
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_admin_departments_index_requires_manage_users(): void
    {
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/admin/departments');
        $this->assertStatus(403, $res);
        $this->assertEnvelopeCode(40300, $res);
    }

    public function test_admin_locations_index_allowed_for_admin(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/locations');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        self::assertNotEmpty($this->json($res)['data']);
    }

    public function test_admin_departments_index_allowed_for_admin(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/admin/departments');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_scope_aware_locations_endpoint_returns_only_in_scope(): void
    {
        $this->loginAs('finance'); // pinned to NORTH
        $res = $this->request('GET', '/api/v1/locations');
        $this->assertStatus(200, $res);
        $codes = array_column($this->json($res)['data'], 'code');
        self::assertContains('NORTH', $codes);
        self::assertNotContains('HQ', $codes, 'reference list must hide out-of-scope locations');
    }

    public function test_scope_aware_locations_endpoint_returns_all_for_global(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/locations');
        $this->assertStatus(200, $res);
        $codes = array_column($this->json($res)['data'], 'code');
        self::assertContains('HQ', $codes);
        self::assertContains('NORTH', $codes);
    }

    public function test_scope_aware_departments_endpoint_works(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/departments');
        $this->assertStatus(200, $res);
        self::assertNotEmpty($this->json($res)['data']);
    }

    // ---------------------------------------------------------------------
    // HIGH #2 — schedule adjustment reviewer list scope filter
    // ---------------------------------------------------------------------

    public function test_schedule_adjustment_reviewer_list_filters_by_scope(): void
    {
        $hqEntry = (int)Db::table('schedule_entries')->insertGetId([
            'coach_user_id' => $this->coachId, 'location_id' => $this->hq,
            'starts_at' => '2026-05-01 09:00:00', 'ends_at' => '2026-05-01 10:00:00',
            'title' => 'HQ class', 'status' => 'active', 'created_by' => 1,
        ]);
        $northEntry = (int)Db::table('schedule_entries')->insertGetId([
            'coach_user_id' => $this->coachId, 'location_id' => $this->north,
            'starts_at' => '2026-05-01 11:00:00', 'ends_at' => '2026-05-01 12:00:00',
            'title' => 'NORTH class', 'status' => 'active', 'created_by' => 1,
        ]);
        $hqAdjId = (int)Db::table('schedule_adjustment_requests')->insertGetId([
            'target_entry_id' => $hqEntry,
            'requested_by_user_id' => $this->coachId,
            'proposed_changes_json' => json_encode(['starts_at' => '2026-05-01 10:00:00']),
            'reason' => 'venue issue at HQ — moving start by an hour',
            'status' => 'submitted',
        ]);
        $northAdjId = (int)Db::table('schedule_adjustment_requests')->insertGetId([
            'target_entry_id' => $northEntry,
            'requested_by_user_id' => $this->coachId,
            'proposed_changes_json' => json_encode(['starts_at' => '2026-05-01 12:00:00']),
            'reason' => 'shifting NORTH class start time per coach request',
            'status' => 'submitted',
        ]);

        $this->loginAs('operations'); // pinned to NORTH; has schedule.review_adjustment
        $res = $this->request('GET', '/api/v1/schedule/adjustments?size=50');
        $this->assertStatus(200, $res);
        $rows = $this->json($res)['data']['data'];
        $ids = array_column($rows, 'id');
        self::assertContains($northAdjId, $ids, 'reviewer must see in-scope NORTH adjustment');
        self::assertNotContains($hqAdjId, $ids, 'reviewer MUST NOT see out-of-scope HQ adjustment');
    }

    public function test_schedule_adjustment_reviewer_list_unfiltered_for_global(): void
    {
        $hqEntry = (int)Db::table('schedule_entries')->insertGetId([
            'coach_user_id' => $this->coachId, 'location_id' => $this->hq,
            'starts_at' => '2026-05-02 09:00:00', 'ends_at' => '2026-05-02 10:00:00',
            'title' => 'HQ class g', 'status' => 'active', 'created_by' => 1,
        ]);
        $hqAdjId = (int)Db::table('schedule_adjustment_requests')->insertGetId([
            'target_entry_id' => $hqEntry,
            'requested_by_user_id' => $this->coachId,
            'proposed_changes_json' => json_encode([]),
            'reason' => 'admin should see this row regardless of scope',
            'status' => 'submitted',
        ]);
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/schedule/adjustments?size=50');
        $ids = array_column($this->json($res)['data']['data'], 'id');
        self::assertContains($hqAdjId, $ids);
    }

    // ---------------------------------------------------------------------
    // HIGH #3 — budget allocation index scope filter
    // ---------------------------------------------------------------------

    public function test_budget_allocation_index_filters_by_scope(): void
    {
        $cat = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'AllocCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $period = (int)(Db::table('budget_periods')
            ->where('period_start', '2026-04-01')->where('period_end', '2026-04-30')->value('id'))
            ?: (int)Db::table('budget_periods')->insertGetId([
                'label' => '2026-04', 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            ]);
        $hqAlloc = (int)Db::table('budget_allocations')->insertGetId([
            'category_id' => $cat, 'period_id' => $period, 'scope_type' => 'location',
            'location_id' => $this->hq, 'cap_amount' => '100.00', 'status' => 'active',
        ]);
        $northAlloc = (int)Db::table('budget_allocations')->insertGetId([
            'category_id' => $cat, 'period_id' => $period, 'scope_type' => 'location',
            'location_id' => $this->north, 'cap_amount' => '200.00', 'status' => 'active',
        ]);
        $orgAlloc = (int)Db::table('budget_allocations')->insertGetId([
            'category_id' => $cat, 'period_id' => $period, 'scope_type' => 'org',
            'cap_amount' => '300.00', 'status' => 'active',
        ]);
        $this->loginAs('finance'); // pinned to NORTH
        $res = $this->request('GET', '/api/v1/budget/allocations?size=200');
        $this->assertStatus(200, $res);
        $ids = array_column($this->json($res)['data']['data'], 'id');
        self::assertContains($northAlloc, $ids);
        self::assertContains($orgAlloc, $ids, 'org-wide allocations are visible to all authorized users');
        self::assertNotContains($hqAlloc, $ids, 'HQ-scoped allocation must be hidden from NORTH-pinned finance');
    }

    // ---------------------------------------------------------------------
    // MEDIUM #5 — structured before/after audit metadata
    // ---------------------------------------------------------------------

    public function test_role_update_audit_carries_before_after_permission_snapshots(): void
    {
        $this->loginAs('admin');
        // Create a fresh role with two permissions
        // Role key validator allows letters/digits/_ only, length 2-64.
        $key = 'AuditedRole_' . bin2hex(random_bytes(2));
        $allPerms = (array)Db::table('permissions')->whereIn('key',
            ['attendance.record', 'reimbursement.create'])->column('id', 'key');
        $created = $this->request('POST', '/api/v1/admin/roles', [
            'key' => $key, 'name' => $key, 'description' => 'audit',
            'permissions' => array_values($allPerms),
        ]);
        $this->assertStatus(200, $created);
        $rid = (int)$this->json($created)['data']['id'];

        // Now update: drop attendance.record, add audit.view
        $newPerms = [
            (int)Db::table('permissions')->where('key', 'reimbursement.create')->value('id'),
            (int)Db::table('permissions')->where('key', 'audit.view')->value('id'),
        ];
        $upd = $this->request('PUT', "/api/v1/admin/roles/{$rid}", [
            'name' => $key . ' v2',
            'permissions' => $newPerms,
        ]);
        $this->assertStatus(200, $upd);

        $row = Db::table('audit_logs')
            ->where('action', 'role.updated')
            ->where('target_entity_id', (string)$rid)
            ->order('id', 'desc')->find();
        self::assertNotEmpty($row, 'audit row missing');
        $meta = is_string($row['metadata_json']) ? json_decode($row['metadata_json'], true) : $row['metadata_json'];
        foreach (['permissions_before', 'permissions_after', 'permissions_added', 'permissions_removed'] as $k) {
            self::assertArrayHasKey($k, $meta, "missing metadata.{$k}");
        }
        self::assertContains('attendance.record',   $meta['permissions_before']);
        self::assertNotContains('attendance.record', $meta['permissions_after']);
        self::assertContains('audit.view',          $meta['permissions_after']);
        self::assertContains('attendance.record',   $meta['permissions_removed']);
        self::assertContains('audit.view',          $meta['permissions_added']);
    }

    public function test_user_update_audit_carries_before_after_role_and_scope_snapshots(): void
    {
        $this->loginAs('admin');
        // Create a fresh user with a known role (Coach).
        $coachRoleId = (int)Db::table('roles')->where('key', 'Coach')->value('id');
        $financeRoleId = (int)Db::table('roles')->where('key', 'Finance')->value('id');
        $uname = 'au3-' . bin2hex(random_bytes(2));
        $created = $this->request('POST', '/api/v1/admin/users', [
            'username' => $uname, 'display_name' => 'AU3', 'roles' => [$coachRoleId],
            'scopes' => [['location_id' => $this->hq]],
        ]);
        $this->assertStatus(200, $created);
        $uid = (int)$this->json($created)['data']['id'];

        // Update: replace Coach with Finance, replace HQ scope with NORTH.
        $upd = $this->request('PUT', "/api/v1/admin/users/{$uid}", [
            'display_name' => 'AU3 updated',
            'roles'  => [$financeRoleId],
            'scopes' => [['location_id' => $this->north]],
        ]);
        $this->assertStatus(200, $upd);

        $row = Db::table('audit_logs')
            ->where('action', 'user.updated')
            ->where('target_entity_id', (string)$uid)
            ->order('id', 'desc')->find();
        self::assertNotEmpty($row);
        $meta = is_string($row['metadata_json']) ? json_decode($row['metadata_json'], true) : $row['metadata_json'];
        foreach (['roles_before', 'roles_after', 'roles_added', 'roles_removed',
                  'scopes_before', 'scopes_after'] as $k) {
            self::assertArrayHasKey($k, $meta, "missing metadata.{$k}");
        }
        self::assertContains('Coach',   $meta['roles_before']);
        self::assertContains('Finance', $meta['roles_after']);
        self::assertContains('Coach',   $meta['roles_removed']);
        self::assertContains('Finance', $meta['roles_added']);
        $beforeLocs = array_column($meta['scopes_before'], 'location_id');
        $afterLocs  = array_column($meta['scopes_after'],  'location_id');
        self::assertContains($this->hq,    $beforeLocs);
        self::assertContains($this->north, $afterLocs);
        self::assertNotContains($this->hq, $afterLocs);
    }
}
