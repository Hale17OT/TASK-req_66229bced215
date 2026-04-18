<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\model\LedgerEntry;
use app\service\auth\Authorization;
use think\Response;

class LedgerController extends BaseController
{
    public function index(): Response
    {
        $userId = (int)$this->request->userId;
        $authz  = app()->make(Authorization::class);
        $authz->requirePermission($userId, 'ledger.view');

        $q = LedgerEntry::order('id', 'desc');
        // HIGH fix audit-2 #5: scope-clip ledger rows via parent settlement
        // → reimbursement ownership. Global admins see everything.
        $q = $authz->applyLedgerScope($q, $userId);

        if ($from = $this->request->get('from')) $q->where('posted_at', '>=', $from);
        if ($to   = $this->request->get('to'))   $q->where('posted_at', '<=', $to);
        if ($acct = $this->request->get('account_code')) $q->where('account_code', $acct);
        return json_response(0, 'ok', $q->paginate(['list_rows' => 200, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }
}
