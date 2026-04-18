<?php
namespace app\service\budget;

use app\exception\BusinessException;
use app\model\BudgetAllocation;
use app\model\BudgetPeriod;
use app\service\money\Money;
use think\facade\Db;

/**
 * Budget math (spec §9.5):
 *
 *   available_funds = cap_amount
 *                     - confirmed_spend (= sum of CONSUMED commitments)
 *                     - active_commitments (= sum of PENDING + ACTIVE commitments)
 *
 * Allocation resolution:
 *   The most specific allocation wins for the (category, scope, on-date) tuple:
 *     1. department-scoped allocation matching department_id
 *     2. location-scoped allocation matching location_id
 *     3. org-wide allocation
 *   Active allocations only.
 */
class BudgetService
{
    public function findApplicableAllocation(int $categoryId, ?int $locationId, ?int $departmentId, string $onDate): ?BudgetAllocation
    {
        $period = BudgetPeriod::where('period_start', '<=', $onDate)
            ->where('period_end', '>=', $onDate)
            ->where('status', 'open')
            ->find();
        if (!$period) return null;

        // Try department, then location, then org
        $candidates = [
            ['scope_type' => 'department', 'department_id' => $departmentId],
            ['scope_type' => 'location',   'location_id' => $locationId],
            ['scope_type' => 'org'],
        ];
        foreach ($candidates as $c) {
            if (($c['scope_type'] === 'department' && !$departmentId) ||
                ($c['scope_type'] === 'location'   && !$locationId)) continue;
            $q = BudgetAllocation::where('category_id', $categoryId)
                ->where('period_id', $period->id)
                ->where('scope_type', $c['scope_type'])
                ->where('status', 'active');
            if ($c['scope_type'] === 'department') $q->where('department_id', $c['department_id']);
            if ($c['scope_type'] === 'location')   $q->where('location_id', $c['location_id']);
            $row = $q->find();
            if ($row) return $row;
        }
        return null;
    }

    public function utilization(int $allocationId): array
    {
        $alloc = BudgetAllocation::find($allocationId);
        if (!$alloc) throw new BusinessException('Allocation not found', 40400, 404);
        return $this->utilizationFor($alloc);
    }

    public function utilizationFor(BudgetAllocation $alloc): array
    {
        $sums = Db::table('fund_commitments')
            ->fieldRaw("
                COALESCE(SUM(CASE WHEN status IN ('pending','active') THEN amount ELSE 0 END), 0) AS active,
                COALESCE(SUM(CASE WHEN status = 'consumed' THEN amount ELSE 0 END), 0) AS consumed
            ")
            ->where('allocation_id', $alloc->id)->find();

        $cap       = Money::of((string)$alloc->cap_amount);
        $consumed  = Money::of((string)$sums['consumed']);
        $active    = Money::of((string)$sums['active']);
        $available = $cap->sub($consumed)->sub($active);

        return [
            'allocation_id'      => (int)$alloc->id,
            'category_id'        => (int)$alloc->category_id,
            'period_id'          => (int)$alloc->period_id,
            'scope_type'         => $alloc->scope_type,
            'location_id'        => $alloc->location_id ? (int)$alloc->location_id : null,
            'department_id'      => $alloc->department_id ? (int)$alloc->department_id : null,
            'cap'                => (string)$cap,
            'confirmed_spend'    => (string)$consumed,
            'active_commitments' => (string)$active,
            'available'          => (string)$available,
            'over_cap'           => $available->isNegative(),
        ];
    }

    /** Returns precheck verdict for a proposed reimbursement before submit. */
    public function precheck(int $categoryId, ?int $locationId, ?int $departmentId, string $serviceStart, string $amount): array
    {
        $alloc = $this->findApplicableAllocation($categoryId, $locationId, $departmentId, $serviceStart);
        if (!$alloc) {
            return ['ok' => false, 'reason' => 'no_active_allocation', 'allocation' => null];
        }
        $util = $this->utilizationFor($alloc);
        $remainingAfter = Money::of($util['available'])->sub(Money::of($amount));
        return [
            'ok'             => $remainingAfter->gte(Money::zero()),
            'reason'         => $remainingAfter->isNegative() ? 'over_cap' : 'within_cap',
            'allocation'     => $util,
            'requested'      => Money::of($amount)->toString(),
            'remaining_after'=> (string)$remainingAfter,
        ];
    }
}
