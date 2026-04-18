<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\BusinessException;
use app\model\BudgetAllocation;
use app\service\auth\Authorization;
use app\service\budget\BudgetService;
use think\Response;

class BudgetUtilizationController extends BaseController
{
    private function authz(): Authorization { return app()->make(Authorization::class); }

    public function index(): Response
    {
        $userId = (int)$this->request->userId;
        $this->authz()->requireAny($userId, [
            'budget.view', 'budget.manage_allocations', 'funds.view_commitments',
        ]);
        // BLOCKER fix #4: scope-aware. Non-global viewers see only utilization
        // for allocations whose scope sits inside their data scope.
        $q = BudgetAllocation::where('status', 'active');
        $q = $this->authz()->applyBudgetAllocationScope($q, $userId);

        $svc = app()->make(BudgetService::class);
        $out = [];
        foreach ($q->select() as $a) $out[] = $svc->utilizationFor($a);
        return json_response(0, 'ok', $out);
    }

    /** GET /api/v1/budget/precheck?category_id=&location_id=&department_id=&service_start=&amount= */
    public function precheck(): Response
    {
        $userId = (int)$this->request->userId;
        $this->authz()->requireAny($userId, [
            'budget.view', 'reimbursement.create', 'reimbursement.review',
        ]);
        $cat = (int)$this->request->get('category_id', 0);
        $loc = $this->request->get('location_id') ? (int)$this->request->get('location_id') : null;
        $dep = $this->request->get('department_id') ? (int)$this->request->get('department_id') : null;
        $start = (string)$this->request->get('service_start', date('Y-m-d'));
        $amt = (string)$this->request->get('amount', '0');
        if ($cat <= 0) throw new BusinessException('category_id required', 40000, 422);
        // Scope gate: a non-global caller may only probe utilization for
        // (location, department) pairs inside their own data scope, otherwise
        // they could discover budget state anywhere in the org by ID-guessing.
        // Unscoped probes (org-wide allocation lookup) are allowed; the
        // allocation resolver falls back to the org-wide cap in that case.
        if ($loc !== null || $dep !== null) {
            $this->authz()->assertScopePermitted(
                $userId, $loc, $dep,
                'Requested scope is outside your authorization'
            );
        }
        return json_response(0, 'ok', app()->make(BudgetService::class)->precheck($cat, $loc, $dep, $start, $amt));
    }
}
