<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\model\FundCommitment;
use app\service\auth\Authorization;
use think\Response;

class CommitmentController extends BaseController
{
    public function index(): Response
    {
        $userId = (int)$this->request->userId;
        $authz = app()->make(Authorization::class);
        $authz->requirePermission($userId, 'funds.view_commitments');

        $q = FundCommitment::order('id', 'desc');
        // BLOCKER fix #4: scope filter via parent reimbursement.
        $q = $authz->applyCommitmentScope($q, $userId);

        if ($s = $this->request->get('status')) $q->where('status', $s);
        if ($a = (int)$this->request->get('allocation_id', 0)) $q->where('allocation_id', $a);
        if ($r = (int)$this->request->get('reimbursement_id', 0)) $q->where('reimbursement_id', $r);

        return json_response(0, 'ok', $q->paginate(['list_rows' => 100, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }
}
