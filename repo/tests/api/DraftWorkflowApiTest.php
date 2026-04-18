<?php
namespace Tests\api;

use think\facade\Db;

/**
 * BLOCKER #6 — draft persistence + idempotent replay.
 *
 * The Layui forms call PUT /api/v1/drafts/:token on every input change and
 * DELETE /:token after a successful submit. Idempotency keys make the
 * post-reconnect retry safe — the second submit returns the cached response
 * instead of creating a duplicate row.
 */
class DraftWorkflowApiTest extends ApiTestCase
{
    public function test_draft_save_load_delete_lifecycle(): void
    {
        $this->loginAs('admin');
        $token = 'wf-' . bin2hex(random_bytes(3));

        // 1. Save
        $put = $this->request('PUT', "/api/v1/drafts/{$token}", [
            'amount' => '125.00', 'merchant' => 'WF-Test',
        ]);
        $this->assertStatus(200, $put);
        $this->assertEnvelopeCode(0, $put);

        // 2. Load returns what we saved
        $get = $this->request('GET', "/api/v1/drafts/{$token}");
        $this->assertStatus(200, $get);
        self::assertSame('125.00', $this->json($get)['data']['amount']);

        // 3. Delete clears it
        $del = $this->request('DELETE', "/api/v1/drafts/{$token}");
        $this->assertStatus(200, $del);

        // 4. Load now 404
        $miss = $this->request('GET', "/api/v1/drafts/{$token}");
        $this->assertStatus(404, $miss);
    }

    public function test_replayed_submit_with_same_idem_key_is_not_duplicated(): void
    {
        // Build a fresh budget context
        $this->loginAs('admin');
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'IdemCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $catId, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount'  => '5000.00',
        ]);
        // Create draft + attach pdf
        $draft = $this->request('POST', '/api/v1/reimbursements', [
            'category_id' => $catId, 'amount' => '10.00', 'merchant' => 'IdemM',
            'receipt_no' => 'INV-IDEM-' . bin2hex(random_bytes(2)),
            'service_period_start' => '2026-04-15', 'service_period_end' => '2026-04-15',
        ]);
        $rid = (int)$this->json($draft)['data']['id'];
        $path = sys_get_temp_dir() . '/idem-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->request('POST', "/api/v1/reimbursements/{$rid}/attachments", null,
            ['content-type' => 'multipart/form-data'],
            ['file' => ['name' => 'a.pdf', 'type' => 'application/pdf',
                'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)]]);

        $idemKey = 'replay-test-' . bin2hex(random_bytes(4));

        // First submit
        $first = $this->request('POST', "/api/v1/reimbursements/{$rid}/submit", null,
            ['idempotency-key' => $idemKey]);
        $this->assertStatus(200, $first);

        // Replayed submit with the SAME key — should return the cached response
        // and NOT advance state again or create duplicate workflow steps.
        $stepsBefore = (int)Db::table('approval_workflow_steps')
            ->whereExists(function ($q) use ($rid) {
                $q->table('approval_workflow_instances')
                  ->whereRaw('approval_workflow_instances.id = approval_workflow_steps.instance_id')
                  ->where('approval_workflow_instances.reimbursement_id', $rid);
            })->count();

        $replay = $this->request('POST', "/api/v1/reimbursements/{$rid}/submit", null,
            ['idempotency-key' => $idemKey]);
        $this->assertStatus(200, $replay);
        // Idempotency middleware sets the X-Idempotent-Replay header
        self::assertSame('1', $replay->getHeader('X-Idempotent-Replay'),
            'replay must come from the idempotency cache');

        $stepsAfter = (int)Db::table('approval_workflow_steps')
            ->whereExists(function ($q) use ($rid) {
                $q->table('approval_workflow_instances')
                  ->whereRaw('approval_workflow_instances.id = approval_workflow_steps.instance_id')
                  ->where('approval_workflow_instances.reimbursement_id', $rid);
            })->count();
        self::assertSame($stepsBefore, $stepsAfter, 'replay must NOT create extra workflow rows');
    }

    public function test_submit_succeeds_after_draft_save_then_clear(): void
    {
        $this->loginAs('admin');
        $token = 'reimb-flow-' . bin2hex(random_bytes(2));
        // Pretend a long form session — repeated saves
        $this->request('PUT', "/api/v1/drafts/{$token}", ['amount' => '5.00']);
        $this->request('PUT', "/api/v1/drafts/{$token}", ['amount' => '10.00', 'merchant' => 'M']);
        // ... user finally submits the real entity through the actual API
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'DraftFlowCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $catId, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount'  => '500.00',
        ]);
        $draft = $this->request('POST', '/api/v1/reimbursements', [
            'category_id' => $catId, 'amount' => '10.00', 'merchant' => 'M',
            'receipt_no' => 'INV-DR-' . bin2hex(random_bytes(2)),
            'service_period_start' => '2026-04-15', 'service_period_end' => '2026-04-15',
        ]);
        $this->assertStatus(200, $draft);
        // Frontend would now clear the draft.
        $this->request('DELETE', "/api/v1/drafts/{$token}");
        $miss = $this->request('GET', "/api/v1/drafts/{$token}");
        $this->assertStatus(404, $miss);
    }

    public function test_capabilities_payload_supports_ui_button_gating(): void
    {
        // Backend assertion for HIGH fix #5: the /me payload exposes a stable
        // capability map keyed by feature.action so the frontend can hide
        // buttons the user can't actually invoke.
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/auth/me');
        $this->assertStatus(200, $res);
        $caps = $this->json($res)['data']['capabilities'];
        self::assertArrayHasKey('reimbursement', $caps);
        self::assertArrayHasKey('approve', $caps['reimbursement']);
        self::assertFalse($caps['reimbursement']['approve'], 'coach must NOT see approve action enabled');
        self::assertTrue($caps['reimbursement']['create'],   'coach CAN create');

        $this->forgetSession();
        $this->loginAs('operations');
        $caps2 = $this->json($this->request('GET', '/api/v1/auth/me'))['data']['capabilities'];
        self::assertTrue($caps2['reimbursement']['approve'], 'operations CAN approve');
        self::assertFalse($caps2['reimbursement']['override'], 'operations CANNOT override');

        $this->forgetSession();
        $this->loginAs('admin');
        $caps3 = $this->json($this->request('GET', '/api/v1/auth/me'))['data']['capabilities'];
        self::assertTrue($caps3['reimbursement']['override'], 'admin CAN override');
        self::assertTrue($caps3['is_global']);
    }

    public function test_server_rejects_unauthorized_action_even_if_button_hidden(): void
    {
        // HIGH fix #5: hiding a button must NOT be the only defense — the
        // server must still reject the action. coach is not allowed to
        // approve, and the server returns 403 even if the UI never renders
        // the button.
        $coachId = (int)Db::table('users')->where('username', 'coach')->value('id');
        $catId = (int)Db::table('budget_categories')->insertGetId([
            'name' => 'GuardCat-' . bin2hex(random_bytes(2)), 'status' => 'active',
        ]);
        $rid = (int)Db::table('reimbursements')->insertGetId([
            'reimbursement_no'     => 'R-CG-' . bin2hex(random_bytes(3)),
            'submitter_user_id'    => $coachId,
            'category_id'          => $catId,
            'amount'               => '1.00',
            'merchant'             => 'M',
            'service_period_start' => '2026-04-01',
            'service_period_end'   => '2026-04-01',
            'receipt_no'           => 'CG-' . bin2hex(random_bytes(3)),
            'status'               => 'submitted',
        ]);
        $this->loginAs('coach');
        $res = $this->request('POST', "/api/v1/reimbursements/{$rid}/approve", ['comment' => 'try']);
        $this->assertStatus(403, $res);
    }
}
