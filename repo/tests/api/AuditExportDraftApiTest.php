<?php
namespace Tests\api;

/**
 * Covers /api/v1/audit (GET), /api/v1/exports (GET/POST/:id/:id/download),
 * and /api/v1/drafts/:token {GET, PUT, DELETE}.
 */
class AuditExportDraftApiTest extends ApiTestCase
{
    // -------- Audit --------

    public function test_audit_search_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/audit');
        $this->assertStatus(401, $res);
    }

    public function test_audit_search_returns_paginated_entries(): void
    {
        $this->loginAs('admin');
        // Ensure at least one audit entry exists
        $this->request('POST', '/api/v1/admin/locations', [
            'code' => 'AUD' . strtoupper(bin2hex(random_bytes(2))),
            'name' => 'AuditLoc',
        ]);
        $res = $this->request('GET', '/api/v1/audit?size=10');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertArrayHasKey('data', $body['data']);
        self::assertGreaterThan(0, count($body['data']['data']));
    }

    // -------- Exports --------

    public function test_exports_index_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/exports');
        $this->assertStatus(401, $res);
    }

    public function test_exports_index_lists_jobs(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/exports');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_exports_create_requires_audit_export_perm(): void
    {
        $this->loginAs('coach');
        $res = $this->request('POST', '/api/v1/exports', [
            'kind' => 'audit', 'filters' => [],
        ]);
        $this->assertStatus(403, $res);
    }

    public function test_exports_create_rejects_unknown_kind(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/exports', [
            'kind' => 'made-up-kind', 'filters' => [],
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_exports_end_to_end_audit_csv(): void
    {
        $this->loginAs('admin');
        $this->request('POST', '/api/v1/admin/locations', [
            'code' => 'EXP' . strtoupper(bin2hex(random_bytes(2))),
            'name' => 'Exp test',
        ]);
        $create = $this->request('POST', '/api/v1/exports', [
            'kind' => 'audit', 'filters' => [],
        ]);
        $this->assertStatus(200, $create);
        $jobId = (int)$this->json($create)['data']['id'];
        self::assertSame('completed', $this->json($create)['data']['status']);

        // show
        $show = $this->request('GET', "/api/v1/exports/{$jobId}");
        $this->assertStatus(200, $show);
        self::assertArrayNotHasKey('file_path', $this->json($show)['data']);

        // download
        $dl = $this->request('GET', "/api/v1/exports/{$jobId}/download");
        $this->assertStatus(200, $dl);
        self::assertStringContainsString('# rows=', (string)$dl->getContent());
        self::assertStringContainsString('# sha256=', (string)$dl->getContent());
    }

    // -------- Drafts --------

    public function test_draft_show_returns_404_for_missing(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/drafts/does-not-exist');
        $this->assertStatus(404, $res);
    }

    public function test_draft_upsert_roundtrip(): void
    {
        $this->loginAs('admin');
        $token = 'draft-token-' . bin2hex(random_bytes(3));
        $up = $this->request('PUT', "/api/v1/drafts/{$token}", [
            'field_a' => 'hello',
            'field_b' => 42,
        ]);
        $this->assertStatus(200, $up);
        $this->assertEnvelopeCode(0, $up);

        $read = $this->request('GET', "/api/v1/drafts/{$token}");
        $this->assertStatus(200, $read);
        $this->assertEnvelopeCode(0, $read);
        self::assertSame('hello', $this->json($read)['data']['field_a']);

        $del = $this->request('DELETE', "/api/v1/drafts/{$token}");
        $this->assertStatus(200, $del);

        $gone = $this->request('GET', "/api/v1/drafts/{$token}");
        $this->assertStatus(404, $gone);
    }
}
