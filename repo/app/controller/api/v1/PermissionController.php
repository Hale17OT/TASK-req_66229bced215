<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\model\Permission;
use app\service\auth\PermissionResolver;
use think\Response;

class PermissionController extends BaseController
{
    public function index(): Response
    {
        if (!app()->make(PermissionResolver::class)->hasAny((int)$this->request->userId, ['auth.manage_roles', 'auth.manage_permissions'])) {
            throw new AuthorizationException('Missing permission');
        }
        $rows = Permission::order('category', 'asc')->order('key', 'asc')->select();
        return json_response(0, 'ok', $rows);
    }
}
