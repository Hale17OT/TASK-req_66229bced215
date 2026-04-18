<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\model\ScheduleEntry;
use app\service\auth\PermissionResolver;
use app\service\auth\ScopeFilter;
use think\Response;

class ScheduleController extends BaseController
{
    public function index(): Response
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->hasAny((int)$this->request->userId, ['schedule.view_assigned', 'schedule.review_adjustment'])) {
            throw new AuthorizationException();
        }
        $q = ScheduleEntry::where('status', 'active')->order('starts_at', 'asc');
        // Coaches only see their own; reviewers/admins see scope-permitted
        if (!$r->has((int)$this->request->userId, 'schedule.review_adjustment')) {
            $q->where('coach_user_id', $this->request->userId);
        } else {
            $scope = $r->scopeFor((int)$this->request->userId);
            $sf = app()->make(ScopeFilter::class);
            $q = $sf->apply($q, $scope, 'location_id', 'department_id');
        }
        if ($from = $this->request->get('from')) $q->where('starts_at', '>=', $from);
        if ($to   = $this->request->get('to'))   $q->where('starts_at', '<=', $to);
        $size = min(500, max(10, (int)$this->request->get('size', 100)));
        return json_response(0, 'ok', $q->paginate(['list_rows' => $size, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }
}
