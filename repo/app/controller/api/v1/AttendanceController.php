<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\AttendanceRecord;
use app\service\audit\AuditService;
use app\service\auth\PermissionResolver;
use app\service\auth\ScopeFilter;
use think\Response;

class AttendanceController extends BaseController
{
    public function index(): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->hasAny((int)$this->request->userId, ['attendance.record', 'attendance.review_correction'])) {
            throw new AuthorizationException();
        }
        $scope = $r->scopeFor((int)$this->request->userId);
        $q = AttendanceRecord::order('occurred_at', 'desc');
        $sf = app()->make(ScopeFilter::class);
        // Model::order() already returns a think\db\Query — pass it through.
        $q = $sf->apply($q, $scope, 'location_id', 'location_id');
        if ($from = $this->request->get('from')) $q->where('occurred_at', '>=', $from);
        if ($to   = $this->request->get('to'))   $q->where('occurred_at', '<=', $to);
        if ($loc  = (int)$this->request->get('location_id', 0)) $q->where('location_id', $loc);
        if ($status = $this->request->get('status')) $q->where('status', $status);
        $size = min(200, max(10, (int)$this->request->get('size', 50)));
        $page = max(1, (int)$this->request->get('page', 1));
        return json_response(0, 'ok', $q->paginate(['list_rows' => $size, 'page' => $page]));
    }

    public function record(): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, 'attendance.record')) {
            throw new AuthorizationException();
        }
        $data = $this->request->only(['location_id', 'member_reference', 'member_name', 'occurred_at', 'attendance_type', 'notes'], 'post');
        $locId = (int)($data['location_id'] ?? 0);
        if ($locId <= 0) throw new BusinessException('location_id required', 40000, 422, ['location_id' => ['required']]);
        $scope = $r->scopeFor((int)$this->request->userId);
        $sf = app()->make(ScopeFilter::class);
        if (!$sf->permits($scope, $locId, null)) {
            throw new AuthorizationException('Location is outside your scope');
        }
        $row = AttendanceRecord::create([
            'location_id'         => $locId,
            'recorded_by_user_id' => (int)$this->request->userId,
            'member_reference'    => $data['member_reference'] ?? null,
            'member_name'         => $data['member_name'] ?? null,
            'occurred_at'         => $data['occurred_at'] ?? date('Y-m-d H:i:s'),
            'attendance_type'     => $data['attendance_type'] ?? null,
            'notes'               => $data['notes'] ?? null,
            'status'              => 'active',
        ]);
        app()->make(AuditService::class)->record('attendance.recorded', 'attendance_record', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }
}
