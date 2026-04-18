<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\BudgetAllocation;
use app\model\BudgetPeriod;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use app\service\auth\PermissionResolver;
use app\service\money\Money;
use think\facade\Db;
use think\Response;

class BudgetAllocationController extends BaseController
{
    private function requirePerm(string $p): void
    {
        if (!app()->make(PermissionResolver::class)->has((int)$this->request->userId, $p)) throw new AuthorizationException();
    }

    public function index(): Response
    {
        $userId = (int)$this->request->userId;
        $authz  = app()->make(Authorization::class);
        $authz->requireAny($userId, ['budget.view', 'budget.manage_allocations']);

        $q = BudgetAllocation::order('period_id', 'desc')->order('id', 'desc');
        // HIGH fix audit-3 #3: scope-clip allocations to caller's authorized
        // location/department. Org-scoped allocations remain visible to all
        // authorized users; admins/global see everything.
        $q = $authz->applyBudgetAllocationScope($q, $userId);

        if ($cat = (int)$this->request->get('category_id', 0)) $q->where('category_id', $cat);
        if ($per = (int)$this->request->get('period_id', 0)) $q->where('period_id', $per);
        if ($s   = $this->request->get('status'))            $q->where('status', $s);
        return json_response(0, 'ok', $q->paginate(['list_rows' => 100, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }

    public function create(): Response
    {
        $this->requirePerm('budget.manage_allocations');
        $data = $this->request->only([
            'category_id', 'period_id', 'period_start', 'period_end',
            'scope_type', 'location_id', 'department_id', 'cap_amount', 'notes'
        ], 'post');
        $catId = (int)($data['category_id'] ?? 0);
        if ($catId <= 0) throw new BusinessException('category_id required', 40000, 422);
        $cap = Money::of((string)($data['cap_amount'] ?? '0'));
        if (!$cap->isPositive()) throw new BusinessException('cap_amount must be > 0', 40000, 422, ['cap_amount' => ['> 0']]);

        // Resolve / create period
        $periodId = (int)($data['period_id'] ?? 0);
        if (!$periodId) {
            $start = (string)($data['period_start'] ?? '');
            $end   = (string)($data['period_end'] ?? '');
            if (!$start || !$end || strtotime($start) === false || strtotime($end) === false || $start > $end) {
                throw new BusinessException('period_start <= period_end required', 40000, 422);
            }
            $existing = BudgetPeriod::where('period_start', $start)->where('period_end', $end)->find();
            $periodId = $existing ? (int)$existing->id : (int)BudgetPeriod::create([
                'label' => substr($start, 0, 7),
                'period_start' => $start, 'period_end' => $end,
            ])->id;
        }

        $scope = (string)($data['scope_type'] ?? 'org');
        if (!in_array($scope, ['org', 'location', 'department'], true)) {
            throw new BusinessException('scope_type must be org/location/department', 40000, 422);
        }
        $locId = $scope === 'location'   ? (int)($data['location_id']   ?? 0) : null;
        $depId = $scope === 'department' ? (int)($data['department_id'] ?? 0) : null;
        if (($scope === 'location' && !$locId) || ($scope === 'department' && !$depId)) {
            throw new BusinessException('scope ID required', 40000, 422);
        }

        // Overlap check (spec §17.5): no other ACTIVE allocation for same key
        $clash = BudgetAllocation::where('category_id', $catId)
            ->where('period_id', $periodId)
            ->where('scope_type', $scope)
            ->where('status', 'active')
            ->where(function ($q) use ($locId, $depId) {
                if ($locId !== null) $q->where('location_id', $locId);
                if ($depId !== null) $q->where('department_id', $depId);
            })
            ->find();
        if ($clash) throw new BusinessException('Active allocation already exists for this scope/period', 40901, 409, ['existing' => $clash->toArray()]);

        $row = BudgetAllocation::create([
            'category_id'   => $catId,
            'period_id'     => $periodId,
            'scope_type'    => $scope,
            'location_id'   => $locId,
            'department_id' => $depId,
            'cap_amount'    => (string)$cap,
            'notes'         => $data['notes'] ?? null,
            'status'        => 'active',
            'created_by'    => (int)$this->request->userId,
        ]);
        app()->make(AuditService::class)->record('budget_allocation.created', 'budget_allocation', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    public function update($id): Response
    {
        $this->requirePerm('budget.manage_allocations');
        $row = BudgetAllocation::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $data = $this->request->only(['cap_amount', 'notes', 'status', 'version'], 'put');
        if (isset($data['version']) && (int)$data['version'] !== (int)$row->version) {
            throw new BusinessException('Stale version — reload', 40913, 409);
        }
        $before = $row->toArray();
        // Editing the cap_amount supersedes the old allocation: write a new row, retire old.
        if (isset($data['cap_amount'])) {
            $newCap = Money::of((string)$data['cap_amount']);
            if (!$newCap->isPositive()) throw new BusinessException('cap_amount > 0', 40000, 422);
            $newRow = Db::transaction(function () use ($row, $newCap, $data) {
                $new = BudgetAllocation::create([
                    'category_id' => $row->category_id, 'period_id' => $row->period_id,
                    'scope_type'  => $row->scope_type, 'location_id' => $row->location_id, 'department_id' => $row->department_id,
                    'cap_amount'  => (string)$newCap,
                    'notes'       => $data['notes'] ?? $row->notes,
                    'status'      => 'active',
                    'created_by'  => (int)$this->request->userId,
                ]);
                $row->status = 'superseded';
                $row->superseded_by_id = (int)$new->id;
                $row->version = (int)$row->version + 1;
                $row->save();
                return $new;
            });
            app()->make(AuditService::class)->record('budget_allocation.superseded', 'budget_allocation', $row->id, $before, $newRow->toArray());
            return json_response(0, 'ok', $newRow->toArray());
        }
        if (array_key_exists('notes', $data)) $row->notes = $data['notes'];
        if (isset($data['status']) && in_array($data['status'], ['active', 'superseded', 'archived'], true)) {
            $row->status = $data['status'];
        }
        $row->version = (int)$row->version + 1;
        $row->save();
        app()->make(AuditService::class)->record('budget_allocation.updated', 'budget_allocation', $row->id, $before, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }
}
