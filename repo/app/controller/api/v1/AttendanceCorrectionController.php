<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\AttendanceCorrectionRequest;
use app\model\AttendanceRecord;
use app\service\audit\AuditService;
use app\service\auth\PermissionResolver;
use app\service\auth\ScopeFilter;
use app\service\workflow\transitions\AttendanceCorrectionMachine;
use think\facade\Db;
use think\Response;

class AttendanceCorrectionController extends BaseController
{
    public function index(): Response
    {
        $r = app()->make(PermissionResolver::class);
        $isReviewer = $r->has((int)$this->request->userId, 'attendance.review_correction');
        $isRequester = $r->has((int)$this->request->userId, 'attendance.request_correction');
        if (!$isReviewer && !$isRequester) throw new AuthorizationException();

        $q = AttendanceCorrectionRequest::order('id', 'desc');
        if (!$isReviewer) {
            $q->where('requested_by_user_id', $this->request->userId);
        } else {
            // Reviewer sees scope-permitted requests
            $scope = $r->scopeFor((int)$this->request->userId);
            $sf = app()->make(ScopeFilter::class);
            $q = $sf->apply($q, $scope, 'location_id', 'location_id');
        }
        if ($s = $this->request->get('status')) $q->where('status', $s);
        $size = min(200, max(10, (int)$this->request->get('size', 50)));
        return json_response(0, 'ok', $q->paginate(['list_rows' => $size, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }

    public function submit(): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'attendance.request_correction')) throw new AuthorizationException();
        $data = $this->request->only(['target_attendance_id', 'proposed_payload', 'reason'], 'post');
        $targetId = (int)($data['target_attendance_id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        $payload = (array)($data['proposed_payload'] ?? []);
        if ($targetId <= 0) throw new BusinessException('target_attendance_id required', 40000, 422);
        if (mb_strlen($reason) < 10) {
            throw new BusinessException('Reason required (min 10 chars)', 40000, 422, ['reason' => ['min 10 chars']]);
        }
        $target = AttendanceRecord::find($targetId);
        if (!$target) throw new BusinessException('Target attendance record not found', 40400, 404);
        $scope = $r->scopeFor((int)$this->request->userId);
        if (!app()->make(ScopeFilter::class)->permits($scope, (int)$target->location_id, null)) {
            throw new AuthorizationException('Target attendance is outside your scope');
        }

        $row = AttendanceCorrectionRequest::create([
            'target_attendance_id'  => $targetId,
            'requested_by_user_id'  => (int)$this->request->userId,
            'location_id'           => (int)$target->location_id,
            'proposed_payload_json' => $payload,
            'reason'                => $reason,
            'status'                => 'submitted',
        ]);
        app()->make(AuditService::class)->record('attendance_correction.submitted', 'attendance_correction', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    public function approve($id): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'attendance.review_correction')) throw new AuthorizationException();
        $cmt = trim((string)$this->request->post('comment', ''));
        $row = AttendanceCorrectionRequest::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        // HIGH fix audit-2 #3: row-level scope check — caller must be in scope
        // for the correction's location (or hold global scope).
        $scope = $r->scopeFor((int)$this->request->userId);
        if (empty($scope['global']) && !app()->make(ScopeFilter::class)->permits($scope, (int)$row->location_id, null)) {
            throw new AuthorizationException('Correction is outside your data scope');
        }
        AttendanceCorrectionMachine::make()->assert($row->status, 'approved');
        $before = $row->toArray();
        $appliedId = Db::transaction(function () use ($row, $cmt) {
            // Mark approved, then immediately apply (write a new attendance record + supersede original)
            $row->status = 'approved';
            $row->reviewer_user_id = $this->request->userId;
            $row->reviewed_at = date('Y-m-d H:i:s');
            $row->review_comment = $cmt ?: null;
            $row->save();

            $original = AttendanceRecord::find($row->target_attendance_id);
            if (!$original) throw new BusinessException('Original attendance vanished', 40400, 404);
            $payload = (array)$row->proposed_payload_json;
            $applied = AttendanceRecord::create([
                'location_id'         => (int)$original->location_id,
                'recorded_by_user_id' => (int)$this->request->userId,
                'member_reference'    => $payload['member_reference'] ?? $original->member_reference,
                'member_name'         => $payload['member_name'] ?? $original->member_name,
                'occurred_at'         => $payload['occurred_at'] ?? $original->occurred_at,
                'attendance_type'     => $payload['attendance_type'] ?? $original->attendance_type,
                'notes'               => $payload['notes'] ?? $original->notes,
                'source_correction_id' => (int)$row->id,
                'status'              => 'active',
            ]);
            // Supersede (NOT delete) the original
            $original->status = 'superseded';
            $original->superseded_by_id = (int)$applied->id;
            $original->save();
            $row->status = 'applied';
            $row->applied_record_id = (int)$applied->id;
            $row->applied_at = date('Y-m-d H:i:s');
            $row->save();
            return (int)$applied->id;
        });
        app()->make(AuditService::class)->record('attendance_correction.applied', 'attendance_correction', $row->id, $before, $row->toArray(), ['applied_record_id' => $appliedId]);
        return json_response(0, 'ok', $row->toArray());
    }

    public function reject($id): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'attendance.review_correction')) throw new AuthorizationException();
        $cmt = trim((string)$this->request->post('comment', ''));
        if (mb_strlen($cmt) < 10) throw new BusinessException('Rejection comment required (min 10 chars)', 40000, 422, ['comment' => ['min 10']]);
        $row = AttendanceCorrectionRequest::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        // HIGH fix audit-2 #3: row-level scope check.
        $scope = $r->scopeFor((int)$this->request->userId);
        if (empty($scope['global']) && !app()->make(ScopeFilter::class)->permits($scope, (int)$row->location_id, null)) {
            throw new AuthorizationException('Correction is outside your data scope');
        }
        AttendanceCorrectionMachine::make()->assert($row->status, 'rejected');
        $before = $row->toArray();
        $row->status = 'rejected';
        $row->reviewer_user_id = $this->request->userId;
        $row->reviewed_at = date('Y-m-d H:i:s');
        $row->review_comment = $cmt;
        $row->save();
        app()->make(AuditService::class)->record('attendance_correction.rejected', 'attendance_correction', $row->id, $before, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    public function withdraw($id): Response
    {
        $row = AttendanceCorrectionRequest::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        if ((int)$row->requested_by_user_id !== (int)$this->request->userId) {
            throw new AuthorizationException('Only the requester can withdraw');
        }
        AttendanceCorrectionMachine::make()->assert($row->status, 'withdrawn');
        $before = $row->toArray();
        $row->status = 'withdrawn';
        $row->save();
        app()->make(AuditService::class)->record('attendance_correction.withdrawn', 'attendance_correction', $row->id, $before, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }
}
