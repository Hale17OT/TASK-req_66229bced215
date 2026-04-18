<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\BusinessException;
use app\model\Department;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use think\Response;

class DepartmentController extends BaseController
{
    private function authz(): Authorization { return app()->make(Authorization::class); }

    /**
     * Admin department list — full enumeration. Requires `auth.manage_users`,
     * matching create() (HIGH fix audit-3 #1).
     */
    public function index(): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'auth.manage_users');
        return json_response(0, 'ok', Department::order('code')->select());
    }

    /**
     * Scope-aware reference listing for Layui forms that need a department
     * dropdown — returns only the departments within the caller's scope.
     */
    public function referenceList(): Response
    {
        $userId = (int)$this->request->userId;
        $scope  = $this->authz()->scopeOf($userId);
        $q = Department::order('code');
        if (empty($scope['global'])) {
            $deps = $scope['departments'] ?? [];
            if (empty($deps)) return json_response(0, 'ok', []);
            $q->whereIn('id', $deps);
        }
        return json_response(0, 'ok', $q->select());
    }

    public function create(): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'auth.manage_users');
        $data = $this->request->only(['code', 'name', 'status'], 'post');
        if (Department::where('code', $data['code'] ?? '')->find()) {
            throw new BusinessException('Code already exists', 40000, 422, ['code' => ['exists']]);
        }
        $row = Department::create([
            'code'   => (string)$data['code'],
            'name'   => (string)$data['name'],
            'status' => $data['status'] ?? 'active',
        ]);
        app()->make(AuditService::class)->record('department.created', 'department', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }
}
