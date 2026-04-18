<?php
namespace Tests\api;

/**
 * Covers budget/categories, budget/allocations, budget/utilization,
 * budget/commitments, budget/precheck.
 */
class BudgetApiTest extends ApiTestCase
{
    private function seedCategory(string $suffix = ''): int
    {
        $this->loginAs('admin');
        $res = $this->request('POST', '/api/v1/budget/categories', [
            'name' => 'TestCat' . $suffix . '-' . bin2hex(random_bytes(2)),
        ]);
        $this->assertStatus(200, $res);
        return (int)$this->json($res)['data']['id'];
    }

    // -------- /budget/categories --------

    public function test_get_categories_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/budget/categories');
        $this->assertStatus(401, $res);
    }

    public function test_get_categories_returns_list(): void
    {
        $this->seedCategory();
        $res = $this->request('GET', '/api/v1/budget/categories');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        self::assertIsArray($this->json($res)['data']);
    }

    public function test_post_categories_rejects_duplicate_name(): void
    {
        $this->loginAs('admin');
        $name = 'DupCat-' . bin2hex(random_bytes(2));
        $this->request('POST', '/api/v1/budget/categories', ['name' => $name]);
        $res = $this->request('POST', '/api/v1/budget/categories', ['name' => $name]);
        $this->assertStatus(422, $res);
    }

    public function test_put_categories_updates_name(): void
    {
        $id = $this->seedCategory();
        $res = $this->request('PUT', "/api/v1/budget/categories/{$id}", [
            'name' => 'Renamed-' . bin2hex(random_bytes(2)),
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    // -------- /budget/allocations --------

    public function test_get_allocations_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/budget/allocations');
        $this->assertStatus(401, $res);
    }

    public function test_get_allocations_returns_paginated(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/budget/allocations?size=20');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    public function test_post_allocation_rejects_invalid_scope(): void
    {
        $id = $this->seedCategory();
        $res = $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id,
            'scope_type'  => 'location', // but no location_id provided
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
            'cap_amount'   => '1000.00',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_post_allocation_rejects_zero_cap(): void
    {
        $id = $this->seedCategory();
        $res = $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id,
            'scope_type'  => 'org',
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
            'cap_amount'   => '0',
        ]);
        $this->assertStatus(422, $res);
    }

    public function test_post_allocation_creates_row(): void
    {
        $id = $this->seedCategory();
        $res = $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id,
            'scope_type'  => 'org',
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
            'cap_amount'   => '5000.00',
        ]);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        self::assertSame('5000.00', $this->json($res)['data']['cap_amount']);
    }

    public function test_put_allocation_supersedes_old_version(): void
    {
        $id = $this->seedCategory();
        $create = $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id,
            'scope_type'  => 'org',
            'period_start' => '2026-04-01',
            'period_end'   => '2026-04-30',
            'cap_amount'   => '1000.00',
        ]);
        $aid = (int)$this->json($create)['data']['id'];

        $res = $this->request('PUT', "/api/v1/budget/allocations/{$aid}", [
            'cap_amount' => '2000.00',
        ]);
        $this->assertStatus(200, $res);
        self::assertSame('2000.00', $this->json($res)['data']['cap_amount']);
        self::assertNotSame($aid, $this->json($res)['data']['id'], 'edit should create a new superseding row');
    }

    // -------- /budget/utilization --------

    public function test_get_utilization_returns_shape(): void
    {
        $id = $this->seedCategory();
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount' => '10000.00',
        ]);
        $res = $this->request('GET', '/api/v1/budget/utilization');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertIsArray($body['data']);
        if (!empty($body['data'])) {
            $row = $body['data'][0];
            foreach (['cap', 'confirmed_spend', 'active_commitments', 'available', 'over_cap'] as $k) {
                self::assertArrayHasKey($k, $row);
            }
        }
    }

    // -------- /budget/commitments --------

    public function test_get_commitments_requires_perm(): void
    {
        $this->loginAs('coach'); // no funds.view_commitments
        $res = $this->request('GET', '/api/v1/budget/commitments');
        $this->assertStatus(403, $res);
    }

    public function test_get_commitments_returns_paginated(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/budget/commitments');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }

    // -------- /budget/precheck --------

    public function test_get_precheck_requires_category(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/budget/precheck');
        $this->assertStatus(422, $res);
    }

    public function test_get_precheck_returns_within_cap(): void
    {
        $id = $this->seedCategory();
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount' => '10000.00',
        ]);
        $res = $this->request('GET', "/api/v1/budget/precheck?category_id={$id}&service_start=2026-04-15&amount=100.00");
        $this->assertStatus(200, $res);
        $body = $this->json($res);
        self::assertTrue($body['data']['ok']);
        self::assertSame('within_cap', $body['data']['reason']);
    }

    public function test_get_precheck_returns_over_cap(): void
    {
        $id = $this->seedCategory();
        $this->request('POST', '/api/v1/budget/allocations', [
            'category_id' => $id, 'scope_type' => 'org',
            'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
            'cap_amount' => '50.00',
        ]);
        $res = $this->request('GET', "/api/v1/budget/precheck?category_id={$id}&service_start=2026-04-15&amount=100.00");
        $body = $this->json($res);
        self::assertFalse($body['data']['ok']);
        self::assertSame('over_cap', $body['data']['reason']);
    }
}
