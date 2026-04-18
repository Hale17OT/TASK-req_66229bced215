<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\ApprovalComment;
use app\model\ApprovalWorkflowInstance;
use app\model\ApprovalWorkflowStep;
use app\model\Reimbursement;
use app\model\ReimbursementAttachment;
use app\service\auth\Authorization;
use app\service\reimbursement\DuplicateRegistry;
use app\service\reimbursement\ReimbursementService;
use app\service\security\FieldMasker;
use think\Response;

class ReimbursementController extends BaseController
{
    private function authz(): Authorization { return app()->make(Authorization::class); }
    private function masker(): FieldMasker { return app()->make(FieldMasker::class); }

    /**
     * Index — scope-aware. Submitter sees their own + reviewers see scope-
     * permitted rows. Global admins see everything.
     */
    public function index(): Response
    {
        $authz = $this->authz();
        $userId = (int)$this->request->userId;
        if (!$authz->hasAny($userId, [
            'reimbursement.create', 'reimbursement.review',
            'reimbursement.approve', 'reimbursement.reject',
            'audit.view', 'ledger.view',
        ])) {
            throw new AuthorizationException();
        }

        $q = Reimbursement::order('id', 'desc');
        // BLOCKER fix #4: centralized scope filter on every list.
        $q = $authz->applyReimbursementScope($q, $userId);

        foreach (['status', 'reimbursement_no', 'category_id'] as $f) {
            if ($v = $this->request->get($f)) $q->where($f, '=', $v);
        }
        if ($v = $this->request->get('merchant')) $q->where('merchant', 'like', "%{$v}%");

        $size = min(200, max(10, (int)$this->request->get('size', 50)));
        $page = $q->paginate(['list_rows' => $size, 'page' => max(1, (int)$this->request->get('page', 1))]);

        $items = array_map(fn ($r) => $this->masker()->reimbursement(is_array($r) ? $r : $r->toArray(), $userId), $page->items());
        $out = $page->toArray();
        $out['data'] = $items;
        return json_response(0, 'ok', $out);
    }

    /**
     * Show — object-level authz (BLOCKER fix #2). Returns 403 if the caller
     * cannot view the row (not silent filtering).
     *
     * Carries `attachment_count` so the UI can honor the per-reimbursement
     * cap enforced server-side in AttachmentService before paying the cost
     * of an upload round trip.
     */
    public function show($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewReimbursement((int)$this->request->userId, $r);
        $out = $this->masker()->reimbursement($r->toArray(), (int)$this->request->userId);
        $out['attachment_count'] = (int)ReimbursementAttachment::where('reimbursement_id', $r->id)
            ->whereNull('deleted_at')->count();
        return json_response(0, 'ok', $out);
    }

    public function createDraft(): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'reimbursement.create');
        $r = app()->make(ReimbursementService::class)->createDraft((int)$this->request->userId, $this->request->post());
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), (int)$this->request->userId));
    }

    public function updateDraft($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $userId = (int)$this->request->userId;
        // Function-level permission first (prevents RBAC drift on custom roles
        // that happen to own a row), then ownership / object-state check.
        $this->authz()->requirePermission($userId, 'reimbursement.edit_own_draft');
        $this->authz()->assertCanModifyOwnReimbursement($userId, $r, 'edit');
        $r = app()->make(ReimbursementService::class)->updateDraft($r, $userId, $this->request->put());
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), $userId));
    }

    public function submit($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $userId = (int)$this->request->userId;
        $this->authz()->requirePermission($userId, 'reimbursement.submit');
        $this->authz()->assertCanModifyOwnReimbursement($userId, $r, 'submit');
        $r = app()->make(ReimbursementService::class)->submit($r, $userId);
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), $userId));
    }

    public function withdraw($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $userId = (int)$this->request->userId;
        // No dedicated `reimbursement.withdraw` permission in the §11.2 matrix
        // — the owner reverses their own submit, so we require the matching
        // submit permission rather than granting withdraw to anyone who happens
        // to own a row.
        $this->authz()->requirePermission($userId, 'reimbursement.submit');
        $this->authz()->assertCanModifyOwnReimbursement($userId, $r, 'withdraw');
        $r = app()->make(ReimbursementService::class)->withdraw($r, $userId);
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), $userId));
    }

    public function approve($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanReviewReimbursement((int)$this->request->userId, $r, 'reimbursement.approve');
        $cmt = trim((string)$this->request->post('comment', ''));
        $r = app()->make(ReimbursementService::class)->approve($r, (int)$this->request->userId, $cmt ?: null);
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), (int)$this->request->userId));
    }

    public function reject($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanReviewReimbursement((int)$this->request->userId, $r, 'reimbursement.reject');
        $cmt = trim((string)$this->request->post('comment', ''));
        $r = app()->make(ReimbursementService::class)->reject($r, (int)$this->request->userId, $cmt);
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), (int)$this->request->userId));
    }

    public function needsRevision($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->requireAny((int)$this->request->userId, ['reimbursement.review', 'reimbursement.approve']);
        $this->authz()->assertCanReviewReimbursement((int)$this->request->userId, $r, 'reimbursement.review');
        $cmt = trim((string)$this->request->post('comment', ''));
        if (mb_strlen($cmt) < 10) throw new BusinessException('Comment min 10 chars', 40000, 422);
        $r = app()->make(ReimbursementService::class)->needsRevision($r, (int)$this->request->userId, $cmt);
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), (int)$this->request->userId));
    }

    public function override($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanReviewReimbursement((int)$this->request->userId, $r, 'reimbursement.override_cap');
        $reason = trim((string)$this->request->post('reason', ''));
        $r = app()->make(ReimbursementService::class)->override($r, (int)$this->request->userId, $reason);
        return json_response(0, 'ok', $this->masker()->reimbursement($r->toArray(), (int)$this->request->userId));
    }

    /**
     * Pre-submit duplicate probe. Non-throwing variant of the submit-time
     * guard — lets the UI warn the user before they waste the upload round
     * trip. Uses the same blind-index lookup as the submit path so the two
     * decisions cannot diverge.
     *
     * GET /api/v1/reimbursements/duplicate-check?merchant=&receipt_no=&amount=&service_period_start=&service_period_end=&exclude_id=
     */
    public function duplicateCheck(): Response
    {
        $userId = (int)$this->request->userId;
        // Draft authors and reviewers both benefit from this probe.
        $this->authz()->requireAny($userId, [
            'reimbursement.create', 'reimbursement.review',
        ]);
        $merchant  = trim((string)$this->request->get('merchant', ''));
        $receiptNo = trim((string)$this->request->get('receipt_no', ''));
        if ($merchant === '' || $receiptNo === '') {
            throw new BusinessException('merchant and receipt_no are required', 40000, 422);
        }
        $amount = (string)$this->request->get('amount', '0');
        $start  = (string)$this->request->get('service_period_start', date('Y-m-d'));
        $end    = (string)$this->request->get('service_period_end', $start);
        $excludeId = (int)$this->request->get('exclude_id', 0);

        $result = app()->make(DuplicateRegistry::class)
            ->check($excludeId, $merchant, $receiptNo, $amount, $start, $end);
        return json_response(0, 'ok', $result);
    }

    /**
     * History — same object-level authz as show (BLOCKER fix #2).
     */
    public function history($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->authz()->assertCanViewReimbursement((int)$this->request->userId, $r);
        $instance = ApprovalWorkflowInstance::where('reimbursement_id', $id)->find();
        $steps = $instance ? ApprovalWorkflowStep::where('instance_id', $instance->id)->order('id')->select() : [];
        $comments = ApprovalComment::where('reimbursement_id', $id)->order('id')->select();
        return json_response(0, 'ok', ['instance' => $instance, 'steps' => $steps, 'comments' => $comments]);
    }
}
