<?php
namespace Tests\api;

/**
 * Covers /api/v1/reimbursements/:id/attachments (POST upload)
 * and /api/v1/reimbursements/attachments/:id (GET download).
 */
class AttachmentApiTest extends ApiTestCase
{
    private function seedCatAndDraft(): array
    {
        $this->loginAs('admin');
        $cat = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'AttCat-' . bin2hex(random_bytes(2)),
        ]);
        $catId = (int)$this->json($cat)['data']['id'];
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $catId, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount'  => '1000.00',
        ]);
        $draft = $this->request('POST', '/api/v1/reimbursements', [
            'category_id' => $catId,
            'amount'      => '10.00',
            'merchant'    => 'M',
            'receipt_no'  => 'ATT-' . bin2hex(random_bytes(2)),
            'service_period_start' => '2026-04-15',
            'service_period_end'   => '2026-04-15',
        ]);
        return [$catId, (int)$this->json($draft)['data']['id']];
    }

    private function makePdf(string $content = "%PDF-1.4\n%%EOF\n"): string
    {
        $p = sys_get_temp_dir() . '/stud-att-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($p, $content);
        return $p;
    }

    public function test_upload_requires_auth(): void
    {
        $res = $this->request('POST', '/api/v1/reimbursements/1/attachments');
        $this->assertStatus(401, $res);
    }

    public function test_upload_rejects_non_allowed_mime(): void
    {
        [, $rid] = $this->seedCatAndDraft();
        $path = sys_get_temp_dir() . '/stud-bad-' . bin2hex(random_bytes(4)) . '.exe';
        file_put_contents($path, "MZthisisnotpdf");
        $files = ['file' => [
            'name' => 'bad.exe', 'type' => 'application/octet-stream',
            'tmp_name' => $path, 'error' => 0, 'size' => filesize($path),
        ]];
        $res = $this->request(
            'POST',
            "/api/v1/reimbursements/{$rid}/attachments",
            null,
            ['content-type' => 'multipart/form-data'],
            $files
        );
        $this->assertStatus(422, $res);
    }

    public function test_upload_then_download_round_trip(): void
    {
        [, $rid] = $this->seedCatAndDraft();
        $path = $this->makePdf();
        $files = ['file' => [
            'name' => 'ok.pdf', 'type' => 'application/pdf',
            'tmp_name' => $path, 'error' => 0, 'size' => filesize($path),
        ]];
        $up = $this->request(
            'POST',
            "/api/v1/reimbursements/{$rid}/attachments",
            null,
            ['content-type' => 'multipart/form-data'],
            $files
        );
        $this->assertStatus(200, $up);
        $aid = (int)$this->json($up)['data']['id'];
        // storage_path must NOT be exposed
        self::assertArrayNotHasKey('storage_path', $this->json($up)['data']);

        $dl = $this->request('GET', "/api/v1/reimbursements/attachments/{$aid}");
        $this->assertStatus(200, $dl);
        self::assertStringStartsWith('%PDF', (string)$dl->getContent());
    }

    public function test_download_unknown_returns_404(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/reimbursements/attachments/999999');
        $this->assertStatus(404, $res);
    }
}
