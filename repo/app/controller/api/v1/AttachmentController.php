<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\Reimbursement;
use app\model\ReimbursementAttachment;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use app\service\reimbursement\AttachmentService;
use app\service\security\FieldMasker;
use think\Response;

class AttachmentController extends BaseController
{
    public function upload($id): Response
    {
        $r = Reimbursement::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $authz = app()->make(Authorization::class);
        $userId = (int)$this->request->userId;

        // Object-level: submitter (always) OR reviewer/approver in scope.
        if ((int)$r->submitter_user_id !== $userId) {
            $authz->assertCanReviewReimbursement($userId, $r, 'reimbursement.review');
        }
        if (!in_array($r->status, ['draft', 'needs_revision'], true)
            && !$authz->has($userId, 'reimbursement.review')) {
            throw new BusinessException('Cannot attach in current status', 40901, 409);
        }
        $file = $this->request->file('file');
        if (!$file) throw new BusinessException('file required', 40000, 422);

        $att = app()->make(AttachmentService::class)->attach($r, $file, $userId);
        // Audit with masked metadata to avoid leaking original filename in logs.
        $maskedAudit = ['file_name_masked' => app()->make(FieldMasker::class)->maskFilename($att->file_name),
            'mime_type' => $att->mime_type, 'size_bytes' => $att->size_bytes, 'sha256' => $att->sha256];
        app()->make(AuditService::class)->record('reimbursement.attachment.uploaded', 'reimbursement', $r->id, null, $maskedAudit);

        $masker = app()->make(FieldMasker::class);
        return json_response(0, 'ok', $masker->attachment($att->toArray(), $userId));
    }

    public function download($id): Response
    {
        $att = ReimbursementAttachment::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        if ($att->deleted_at) throw new BusinessException('Not found', 40400, 404);
        $r = Reimbursement::find($att->reimbursement_id) ?: throw new BusinessException('Not found', 40400, 404);

        // Object-level authz: same rules as reimbursement view.
        app()->make(Authorization::class)->assertCanViewReimbursement((int)$this->request->userId, $r);

        if (!is_file((string)$att->storage_path)) throw new BusinessException('File missing on disk', 50000, 500);

        app()->make(AuditService::class)->record('reimbursement.attachment.downloaded', 'reimbursement_attachment', $att->id);
        return response(file_get_contents((string)$att->storage_path), 200)
            ->contentType((string)$att->mime_type)
            ->header(['Content-Disposition' => 'attachment; filename="' . $att->file_name . '"']);
    }
}
