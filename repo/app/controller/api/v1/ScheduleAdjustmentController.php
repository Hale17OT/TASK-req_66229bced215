<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\ScheduleAdjustmentRequest;
use app\model\ScheduleEntry;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use app\service\auth\PermissionResolver;
use app\service\workflow\transitions\ScheduleAdjustmentMachine;
use think\facade\Db;
use think\Response;

class ScheduleAdjustmentController extends BaseController
{
    public function index(): Response
    {
        $r = app()->make(PermissionResolver::class);
        $authz = app()->make(Authorization::class);
        $userId = (int)$this->request->userId;
        $reviewer = $r->has($userId, 'schedule.review_adjustment');
        $coach    = $r->has($userId, 'schedule.request_adjustment');
        if (!$reviewer && !$coach) throw new AuthorizationException();

        $q = ScheduleAdjustmentRequest::order('id', 'desc');
        if (!$reviewer) {
            // Coach mode: only the rows they submitted.
            $q->where('requested_by_user_id', $userId);
        } else {
            // HIGH fix audit-3 #2: reviewer mode now joins via target schedule
            // entry and constrains by location/department scope. Out-of-scope
            // rows never appear (matches approve/reject server-side rule).
            $q = $authz->applyScheduleAdjustmentScope($q, $userId);
        }
        if ($s = $this->request->get('status')) $q->where('status', $s);
        $size = min(200, max(10, (int)$this->request->get('size', 50)));
        return json_response(0, 'ok', $q->paginate(['list_rows' => $size, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }

    public function submit(): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'schedule.request_adjustment')) throw new AuthorizationException();
        $data = $this->request->only(['target_entry_id', 'proposed_changes', 'reason'], 'post');
        $targetId = (int)($data['target_entry_id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        $changes = (array)($data['proposed_changes'] ?? []);
        if ($targetId <= 0) throw new BusinessException('target_entry_id required', 40000, 422);
        if (mb_strlen($reason) < 10) throw new BusinessException('Reason required (min 10 chars)', 40000, 422, ['reason' => ['min 10']]);
        $target = ScheduleEntry::find($targetId);
        if (!$target) throw new BusinessException('Target schedule entry not found', 40400, 404);
        if ((int)$target->coach_user_id !== (int)$this->request->userId) {
            throw new AuthorizationException('Coaches may only adjust their own schedule entries');
        }
        $row = ScheduleAdjustmentRequest::create([
            'target_entry_id'      => $targetId,
            'requested_by_user_id' => (int)$this->request->userId,
            'proposed_changes_json' => $changes,
            'reason'               => $reason,
            'status'               => 'submitted',
        ]);
        app()->make(AuditService::class)->record('schedule_adjustment.submitted', 'schedule_adjustment', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    public function approve($id): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'schedule.review_adjustment')) throw new AuthorizationException();
        $cmt = trim((string)$this->request->post('comment', ''));
        $row = ScheduleAdjustmentRequest::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->assertScopePermitsAdjustment($r, (int)$this->request->userId, $row);
        ScheduleAdjustmentMachine::make()->assert($row->status, 'approved');
        $before = $row->toArray();
        $appliedId = Db::transaction(function () use ($row, $cmt) {
            $row->status = 'approved';
            $row->reviewer_user_id = $this->request->userId;
            $row->reviewed_at = date('Y-m-d H:i:s');
            $row->review_comment = $cmt ?: null;
            $row->save();
            $original = ScheduleEntry::find($row->target_entry_id);
            if (!$original) throw new BusinessException('Original schedule vanished', 40400, 404);
            $changes = (array)$row->proposed_changes_json;
            $applied = ScheduleEntry::create([
                'coach_user_id' => (int)$original->coach_user_id,
                'location_id'   => $changes['location_id'] ?? $original->location_id,
                'department_id' => $changes['department_id'] ?? $original->department_id,
                'starts_at'     => $changes['starts_at'] ?? $original->starts_at,
                'ends_at'       => $changes['ends_at'] ?? $original->ends_at,
                'title'         => $changes['title'] ?? $original->title,
                'notes'         => $changes['notes'] ?? $original->notes,
                'status'        => 'active',
                'created_by'    => (int)$this->request->userId,
            ]);
            $original->status = 'superseded';
            $original->superseded_by_id = (int)$applied->id;
            $original->save();
            $row->status = 'applied';
            $row->applied_entry_id = (int)$applied->id;
            $row->applied_at = date('Y-m-d H:i:s');
            $row->save();
            return (int)$applied->id;
        });
        app()->make(AuditService::class)->record('schedule_adjustment.applied', 'schedule_adjustment', $row->id, $before, $row->toArray(), ['applied_entry_id' => $appliedId]);
        return json_response(0, 'ok', $row->toArray());
    }

    public function reject($id): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'schedule.review_adjustment')) throw new AuthorizationException();
        $cmt = trim((string)$this->request->post('comment', ''));
        if (mb_strlen($cmt) < 10) throw new BusinessException('Rejection comment required (min 10 chars)', 40000, 422, ['comment' => ['min 10']]);
        $row = ScheduleAdjustmentRequest::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->assertScopePermitsAdjustment($r, (int)$this->request->userId, $row);
        ScheduleAdjustmentMachine::make()->assert($row->status, 'rejected');
        $before = $row->toArray();
        $row->status = 'rejected';
        $row->reviewer_user_id = $this->request->userId;
        $row->reviewed_at = date('Y-m-d H:i:s');
        $row->review_comment = $cmt;
        $row->save();
        app()->make(AuditService::class)->record('schedule_adjustment.rejected', 'schedule_adjustment', $row->id, $before, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    public function withdraw($id): Response
    {
        $row = ScheduleAdjustmentRequest::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        if ((int)$row->requested_by_user_id !== (int)$this->request->userId) throw new AuthorizationException();
        ScheduleAdjustmentMachine::make()->assert($row->status, 'withdrawn');
        $before = $row->toArray();
        $row->status = 'withdrawn';
        $row->save();
        app()->make(AuditService::class)->record('schedule_adjustment.withdrawn', 'schedule_adjustment', $row->id, $before, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    /**
     * HIGH fix audit-2 #4: enforce row-level scope for reviewer actions.
     * The reviewer must hold a scope that covers the adjustment's *current*
     * target schedule entry (location AND/OR department). The proposed
     * change cannot move the entry outside the reviewer's scope either.
     */
    private function assertScopePermitsAdjustment(PermissionResolver $r, int $userId, ScheduleAdjustmentRequest $row): void
    {
        $scope = $r->scopeFor($userId);
        if (!empty($scope['global'])) return;

        $sf = app()->make(\app\service\auth\ScopeFilter::class);
        $entry = ScheduleEntry::find($row->target_entry_id);
        if (!$entry) {
            throw new BusinessException('Target schedule entry vanished', 40400, 404);
        }
        $loc = $entry->location_id !== null ? (int)$entry->location_id : null;
        $dep = $entry->department_id !== null ? (int)$entry->department_id : null;
        if (!$sf->permits($scope, $loc, $dep)) {
            throw new AuthorizationException('Schedule entry is outside your data scope');
        }
        // Reviewer cannot approve a change that *moves* the entry into a
        // location/department they do not control.
        $changes = (array)$row->proposed_changes_json;
        $newLoc = isset($changes['location_id']) ? (int)$changes['location_id'] : $loc;
        $newDep = isset($changes['department_id']) ? (int)$changes['department_id'] : $dep;
        if (!$sf->permits($scope, $newLoc, $newDep)) {
            throw new AuthorizationException('Proposed location/department is outside your data scope');
        }
    }
}
