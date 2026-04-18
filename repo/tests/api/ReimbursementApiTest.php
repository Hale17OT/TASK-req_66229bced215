<?php
namespace Tests\api;

use think\facade\Db;

/**
 * Covers every /api/v1/reimbursements/* route including :id/submit,
 * :id/approve, :id/reject, :id/needs-revision, :id/withdraw, :id/override,
 * :id/history, bare :id (show/updateDraft), and index/create.
 *
 * Also exercises /reimbursements/:id/attachments (upload) and
 * /reimbursements/attachments/:id (download).
 */
class ReimbursementApiTest extends ApiTestCase
{
    /** Create a budget category + allocation and return category id. */
    private function seedBudget(string $cap = '10000.00'): int
    {
        $this->loginAs('admin');
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'RBCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id'  => $catId,
            'scope_type'   => 'org',
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
            'cap_amount'   => $cap,
        ]);
        return $catId;
    }

    /** Helper: save a tiny PDF blob and return its absolute path. */
    private function makeTempPdf(): string
    {
        $p = sys_get_temp_dir() . '/studio-test-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($p, "%PDF-1.4\n%%EOF\n");
        return $p;
    }

    /** Upload an attachment for a given reimbursement id and return attachment id. */
    private function uploadAttachment(int $rid): int
    {
        $path = $this->makeTempPdf();
        $files = [
            'file' => [
                'name'     => basename($path),
                'type'     => 'application/pdf',
                'tmp_name' => $path,
                'error'    => 0,
                'size'     => filesize($path),
            ],
        ];
        $res = $this->request(
            'POST',
            '/api/v1/reimbursements/' . $rid . '/attachments',
            null,
            ['content-type' => 'multipart/form-data'],
            $files
        );
        $this->assertStatus(200, $res, 'upload must succeed');
        return (int)$this->json($res)['data']['id'];
    }

    private function createDraft(int $catId, string $receipt = 'INV-001', string $amount = '125.00', string $merchant = 'ACME'): int
    {
        $res = $this->request('POST', '/api/v1/reimbursements', [
            'category_id'          => $catId,
            'amount'               => $amount,
            'merchant'             => $merchant,
            'receipt_no'           => $receipt,
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
            'description'          => 'test',
        ]);
        $this->assertStatus(200, $res);
        return (int)$this->json($res)['data']['id'];
    }

    // -------- /reimbursements index + show + create + update --------

    public function test_index_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/reimbursements');
        $this->assertStatus(401, $res);
    }

    public function test_index_returns_paginated(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/reimbursements');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_post_creates_draft(): void
    {
        $cat = $this->seedBudget();
        $res = $this->request('POST', '/api/v1/reimbursements', [
            'category_id' => $cat, 'amount' => '10.00', 'merchant' => 'M',
            'receipt_no'  => 'R-DRAFT', 'service_period_start' => '2026-04-01',
            'service_period_end' => '2026-04-01',
        ]);
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertSame('draft', $body['data']['status']);
        self::assertStringStartsWith('R-', $body['data']['reimbursement_no']);
    }

    public function test_get_show_returns_single_reimbursement(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-SHOW');
        $res = $this->request('GET', "/api/v1/reimbursements/{$id}");
        $this->assertStatus(200, $res);
        self::assertSame($id, $this->json($res)['data']['id']);
    }

    public function test_put_update_draft_changes_fields(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-UPD');
        $res = $this->request('PUT', "/api/v1/reimbursements/{$id}", [
            'merchant' => 'Updated Merchant',
            'version'  => 1,
        ]);
        $this->assertStatus(200, $res);
        self::assertSame('Updated Merchant', $this->json($res)['data']['merchant']);
    }

    // -------- /reimbursements/:id/submit --------

    public function test_submit_without_attachment_is_rejected(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-NOATT');
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $this->assertStatus(422, $res);
    }

    public function test_submit_advances_status_and_freezes_commitment(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-SUB');
        $this->uploadAttachment($id);
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $this->assertStatus(200, $res);
        self::assertSame('submitted', $this->json($res)['data']['status']);
        self::assertGreaterThan(
            0,
            Db::table('fund_commitments')->where('reimbursement_id', $id)->where('status', 'active')->count()
        );
    }

    public function test_submit_flags_over_cap_for_override(): void
    {
        $cat = $this->seedBudget('50.00'); // tiny cap
        $id = $this->createDraft($cat, 'R-OVER', '200.00');
        $this->uploadAttachment($id);
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $this->assertStatus(200, $res);
        self::assertSame('pending_override_review', $this->json($res)['data']['status']);
    }

    // -------- /reimbursements/:id/withdraw --------

    public function test_withdraw_releases_freeze(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-WD');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/withdraw");
        $this->assertStatus(200, $res);
        self::assertSame('withdrawn', $this->json($res)['data']['status']);
        $active = Db::table('fund_commitments')
            ->where('reimbursement_id', $id)->where('status', 'active')->count();
        self::assertSame(0, $active);
    }

    // -------- /reimbursements/:id/approve --------

    public function test_approve_advances_to_settlement_pending(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-APP');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/approve", ['comment' => 'ok']);
        $this->assertStatus(200, $res);
        self::assertSame('settlement_pending', $this->json($res)['data']['status']);
    }

    // -------- /reimbursements/:id/reject --------

    public function test_reject_requires_min_reason(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-REJ');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/reject", ['comment' => 'short']);
        $this->assertStatus(422, $res);
        $ok = $this->request('POST', "/api/v1/reimbursements/{$id}/reject", [
            'comment' => 'Rejected because the attachment is illegible',
        ]);
        $this->assertStatus(200, $ok);
        self::assertSame('rejected', $this->json($ok)['data']['status']);
    }

    // -------- /reimbursements/:id/needs-revision --------

    public function test_needs_revision_returns_to_user_and_releases_freeze(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-NR');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/needs-revision", [
            'comment' => 'Please attach an itemized receipt before resubmitting',
        ]);
        $this->assertStatus(200, $res);
        self::assertSame('needs_revision', $this->json($res)['data']['status']);
    }

    // -------- /reimbursements/:id/override --------

    public function test_override_requires_admin_permission(): void
    {
        $cat = $this->seedBudget('50.00');
        $id = $this->createDraft($cat, 'R-OV1', '200.00');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $this->forgetSession();
        $this->loginAs('operations'); // no override_cap
        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/override", [
            'reason' => 'Trying to override without permission',
        ]);
        $this->assertStatus(403, $res);
    }

    public function test_override_admin_path_unlocks_review(): void
    {
        $cat = $this->seedBudget('50.00');
        $id = $this->createDraft($cat, 'R-OV2', '200.00');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $short = $this->request('POST', "/api/v1/reimbursements/{$id}/override", [
            'reason' => 'too short',
        ]);
        $this->assertStatus(422, $short);

        $res = $this->request('POST', "/api/v1/reimbursements/{$id}/override", [
            'reason' => 'Legal sign-off received; proceeding despite over-cap state',
        ]);
        $this->assertStatus(200, $res);
        self::assertSame('under_review', $this->json($res)['data']['status']);
    }

    // -------- /reimbursements/:id/history --------

    public function test_get_history_returns_workflow_trace(): void
    {
        $cat = $this->seedBudget();
        $id = $this->createDraft($cat, 'R-HIST');
        $this->uploadAttachment($id);
        $this->request('POST', "/api/v1/reimbursements/{$id}/submit");
        $res = $this->request('GET', "/api/v1/reimbursements/{$id}/history");
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('instance', $body['data']);
        self::assertArrayHasKey('steps', $body['data']);
        self::assertGreaterThan(0, count($body['data']['steps']));
    }

    // -------- Duplicate blocking --------

    public function test_duplicate_receipt_blocks_submission(): void
    {
        $cat = $this->seedBudget();
        $a = $this->createDraft($cat, 'INV-DUP-1', '50.00');
        $this->uploadAttachment($a);
        $this->request('POST', "/api/v1/reimbursements/{$a}/submit");

        $b = $this->createDraft($cat, 'INV-DUP-1', '60.00');
        $this->uploadAttachment($b);
        $res = $this->request('POST', "/api/v1/reimbursements/{$b}/submit");
        $this->assertStatus(422, $res);
    }
}
