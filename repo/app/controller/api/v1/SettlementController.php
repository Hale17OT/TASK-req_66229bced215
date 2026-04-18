<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\BusinessException;
use app\model\Reimbursement;
use app\model\SettlementRecord;
use app\service\auth\Authorization;
use app\service\security\FieldMasker;
use app\service\settlement\SettlementService;
use think\Response;

class SettlementController extends BaseController
{
    private function authz(): Authorization { return app()->make(Authorization::class); }
    private function masker(): FieldMasker { return app()->make(FieldMasker::class); }

    /**
     * Index — RBAC + scope filter (BLOCKER fix #4).
     */
    public function index(): Response
    {
        $userId = (int)$this->request->userId;
        $this->authz()->requireAny($userId, [
            'settlement.record', 'settlement.confirm', 'settlement.refund', 'ledger.view',
        ]);

        $q = SettlementRecord::order('id', 'desc');
        $q = $this->authz()->applySettlementScope($q, $userId);

        if ($s = $this->request->get('status')) $q->where('status', $s);
        if ($r = (int)$this->request->get('reimbursement_id', 0)) $q->where('reimbursement_id', $r);

        $page = $q->paginate(['list_rows' => 100, 'page' => max(1, (int)$this->request->get('page', 1))]);
        $items = array_map(
            fn ($r) => $this->masker()->settlement(is_array($r) ? $r : $r->toArray(), $userId),
            $page->items()
        );
        $out = $page->toArray(); $out['data'] = $items;
        return json_response(0, 'ok', $out);
    }

    /**
     * Show — object-level authz (BLOCKER fix #3): caller must hold a
     * settlement/ledger permission AND the parent reimbursement must lie in
     * their scope. Returns 403 (not 200 with masked data) when refused.
     */
    public function show($id): Response
    {
        $row = SettlementRecord::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewSettlement((int)$this->request->userId, $row);
        return json_response(0, 'ok', $this->masker()->settlement($row->toArray(), (int)$this->request->userId));
    }

    public function record(): Response
    {
        $userId = (int)$this->request->userId;
        $this->authz()->requirePermission($userId, 'settlement.record');
        $data = $this->request->only(['reimbursement_id', 'method', 'gross_amount', 'check_number', 'terminal_batch_ref', 'cash_receipt_ref', 'notes'], 'post');
        $rid = (int)($data['reimbursement_id'] ?? 0);
        if ($rid <= 0) throw new BusinessException('reimbursement_id required', 40000, 422);
        // Object/scope gate: caller must be able to *view* the target
        // reimbursement before they can record a settlement against it.
        // Without this a finance user could guess foreign reimbursement IDs
        // and post settlements outside their scope.
        $target = Reimbursement::find($rid) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewReimbursement($userId, $target);
        $svc = app()->make(SettlementService::class);
        $row = $svc->record($rid, $data, $userId);
        return json_response(0, 'ok', $this->masker()->settlement($row->toArray(), $userId));
    }

    public function confirm($id): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'settlement.confirm');
        $row = SettlementRecord::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewSettlement((int)$this->request->userId, $row);
        $row = app()->make(SettlementService::class)->confirm($row, (int)$this->request->userId);
        return json_response(0, 'ok', $this->masker()->settlement($row->toArray(), (int)$this->request->userId));
    }

    public function refund($id): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'settlement.refund');
        $row = SettlementRecord::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewSettlement((int)$this->request->userId, $row);
        $amt = (string)$this->request->post('amount', '0');
        $reason = trim((string)$this->request->post('reason', ''));
        if (mb_strlen($reason) < 5) throw new BusinessException('reason required', 40000, 422);
        $refund = app()->make(SettlementService::class)->refund($row, $amt, $reason, (int)$this->request->userId);
        return json_response(0, 'ok', $refund->toArray());
    }

    public function markException($id): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'settlement.confirm');
        $row = SettlementRecord::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewSettlement((int)$this->request->userId, $row);
        $reason = trim((string)$this->request->post('reason', ''));
        if (mb_strlen($reason) < 5) throw new BusinessException('reason required', 40000, 422);
        $row = app()->make(SettlementService::class)->markException($row, $reason, (int)$this->request->userId);
        return json_response(0, 'ok', $this->masker()->settlement($row->toArray(), (int)$this->request->userId));
    }
}
