<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\BusinessException;
use app\model\Location;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use think\Response;

class LocationController extends BaseController
{
    private function authz(): Authorization { return app()->make(Authorization::class); }

    /**
     * Admin location list — full enumeration. Requires `auth.manage_users`,
     * matching the create() method (HIGH fix audit-3 #1).
     *
     * Non-admin code paths (e.g. the Front Desk attendance dropdown) MUST use
     * the scope-aware reference endpoint at `/api/v1/locations`, served by
     * `referenceList()` below.
     */
    public function index(): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'auth.manage_users');
        return json_response(0, 'ok', Location::order('code')->select());
    }

    /**
     * Scope-aware reference listing. Returns only locations within the
     * caller's data scope (admins and global users see all). Used by Layui
     * forms that need a location dropdown (e.g. attendance recording) — they
     * cannot enumerate the full org chart, only what they may operate on.
     */
    public function referenceList(): Response
    {
        $userId = (int)$this->request->userId;
        $scope  = $this->authz()->scopeOf($userId);
        $q = Location::order('code');
        if (empty($scope['global'])) {
            $locs = $scope['locations'] ?? [];
            if (empty($locs)) return json_response(0, 'ok', []);
            $q->whereIn('id', $locs);
        }
        return json_response(0, 'ok', $q->select());
    }

    public function create(): Response
    {
        $this->authz()->requirePermission((int)$this->request->userId, 'auth.manage_users');
        $data = $this->request->only(['code', 'name', 'status'], 'post');
        if (Location::where('code', $data['code'] ?? '')->find()) {
            throw new BusinessException('Code already exists', 40000, 422, ['code' => ['exists']]);
        }
        $row = Location::create([
            'code'   => (string)$data['code'],
            'name'   => (string)$data['name'],
            'status' => $data['status'] ?? 'active',
        ]);
        app()->make(AuditService::class)->record('location.created', 'location', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }
}
