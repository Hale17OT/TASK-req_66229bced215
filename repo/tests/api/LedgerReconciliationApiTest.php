<?php
namespace Tests\api;

/**
 * Covers /api/v1/ledger and /api/v1/reconciliation/runs{,POST}.
 */
class LedgerReconciliationApiTest extends ApiTestCase
{
    public function test_ledger_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/ledger');
        $this->assertStatus(401, $res);
    }

    public function test_ledger_requires_view_perm(): void
    {
        $this->loginAs('coach');
        $res = $this->request('GET', '/api/v1/ledger');
        $this->assertStatus(403, $res);
    }

    public function test_ledger_returns_paginated_list(): void
    {
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/ledger');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_reconciliation_runs_index_returns_list(): void
    {
        $this->loginAs('finance');
        $res = $this->request('GET', '/api/v1/reconciliation/runs');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_reconciliation_start_rejects_bad_period(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-12-31',
            'period_end'   => '2026-01-01',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_reconciliation_start_creates_run(): void
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/reconciliation/runs', [
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        self::assertSame('completed', $this->json($res)['data']['status']);
    }
}
