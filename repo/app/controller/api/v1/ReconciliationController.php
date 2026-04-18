<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\BusinessException;
use app\model\ReconciliationRun;
use app\service\auth\Authorization;
use app\service\settlement\ReconciliationService;
use think\Response;

class ReconciliationController extends BaseController
{
    private function authz(): Authorization { return app()->make(Authorization::class); }

    public function index(): Response
    {
        $userId = (int)$this->request->userId;
        $this->authz()->requirePermission($userId, 'ledger.view');
        // Non-global operators see only runs they started. Runs are org-level
        // records — we don't have a location/department column to filter on,
        // so caller identity is the finest-grained gate we can apply here.
        $q = ReconciliationRun::order('id', 'desc');
        $q = $this->authz()->applyReconciliationRunScope($q, $userId);
        return json_response(0, 'ok', $q->paginate([
            'list_rows' => 50,
            'page'      => max(1, (int)$this->request->get('page', 1)),
        ]));
    }

    public function start(): Response
    {
        $userId = (int)$this->request->userId;
        $this->authz()->requireAny($userId, ['ledger.view', 'settlement.confirm']);
        $start = (string)$this->request->post('period_start', date('Y-m-01'));
        $end   = (string)$this->request->post('period_end',   date('Y-m-t'));
        if ($start > $end) throw new BusinessException('period_start <= period_end', 40000, 422);
        $svc = app()->make(ReconciliationService::class);
        return json_response(0, 'ok', $svc->start($userId, $start, $end)->toArray());
    }
}
