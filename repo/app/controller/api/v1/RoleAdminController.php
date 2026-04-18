<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\Role;
use app\service\audit\AuditService;
use app\service\auth\PermissionResolver;
use think\facade\Db;
use think\Response;

class RoleAdminController extends BaseController
{
    private function requirePerm(string $perm): void
    {
        $r = app()->make(PermissionResolver::class);
        if (!$r->has((int)$this->request->userId, $perm)) {
            throw new AuthorizationException('Missing permission: ' . $perm);
        }
    }

    public function index(): Response
    {
        $this->requirePerm('auth.manage_roles');
        $rows = Role::with(['permissions'])->order('id', 'asc')->select();
        return json_response(0, 'ok', $rows);
    }

    public function create(): Response
    {
        $this->requirePerm('auth.manage_roles');
        $data = $this->request->only(['key', 'name', 'description', 'permissions'], 'post');
        $key = trim((string)($data['key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{2,64}$/', $key)) {
            throw new BusinessException('Role key invalid', 40000, 422, ['key' => ['2-64 chars, letters/digits/_']]);
        }
        if (Role::where('key', $key)->find()) {
            throw new BusinessException('Role key already exists', 40000, 422, ['key' => ['Already exists']]);
        }
        $role = Db::transaction(function () use ($data, $key) {
            $r = Role::create([
                'key'        => $key,
                'name'       => (string)($data['name'] ?? $key),
                'description' => $data['description'] ?? null,
                'is_system'  => 0,
            ]);
            $this->syncPerms((int)$r->id, (array)($data['permissions'] ?? []));
            return $r;
        });
        app()->make(AuditService::class)->record('role.created', 'role', $role->id, null, $role->toArray(), ['perms' => $data['permissions'] ?? []]);
        return json_response(0, 'ok', $role->toArray());
    }

    public function update($id): Response
    {
        $this->requirePerm('auth.manage_roles');
        $role = Role::find($id);
        if (!$role) throw new BusinessException('Role not found', 40400, 404);
        $data = $this->request->only(['name', 'description', 'permissions'], 'put');

        // MEDIUM fix audit-3 #5: snapshot the role's current permission list
        // BEFORE mutation so the audit row carries a structured before/after
        // delta, not just the role's own scalar fields.
        $before = $role->toArray();
        $beforePerms = $this->currentPermissions((int)$role->id);

        Db::transaction(function () use ($role, $data) {
            if (isset($data['name']))                   $role->name = (string)$data['name'];
            if (array_key_exists('description', $data)) $role->description = $data['description'];
            $role->save();
            if (array_key_exists('permissions', $data)) {
                $this->syncPerms((int)$role->id, (array)$data['permissions']);
            }
        });
        $afterPerms = $this->currentPermissions((int)$role->id);

        app()->make(AuditService::class)->record(
            'role.updated', 'role', $role->id, $before, $role->toArray(),
            [
                'permissions_before' => $beforePerms,
                'permissions_after'  => $afterPerms,
                'permissions_added'   => array_values(array_diff($afterPerms, $beforePerms)),
                'permissions_removed' => array_values(array_diff($beforePerms, $afterPerms)),
            ]
        );
        return json_response(0, 'ok', $role->toArray());
    }

    /** Returns the role's current permission keys, sorted, for stable diffs. */
    private function currentPermissions(int $roleId): array
    {
        $keys = Db::table('role_permissions')->alias('rp')
            ->leftJoin('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->whereNotNull('p.key')
            ->column('p.key');
        sort($keys);
        return array_values(array_unique($keys));
    }

    public function destroy($id): Response
    {
        $this->requirePerm('auth.manage_roles');
        $role = Role::find($id);
        if (!$role) throw new BusinessException('Role not found', 40400, 404);
        if ($role->is_system) throw new BusinessException('System roles cannot be deleted', 40300, 403);
        $assigned = (int)Db::table('user_roles')->where('role_id', $role->id)->count();
        if ($assigned > 0) throw new BusinessException("Role still assigned to {$assigned} user(s)", 40901, 409);
        Db::transaction(function () use ($role) {
            Db::table('role_permissions')->where('role_id', $role->id)->delete();
            $role->delete();
        });
        app()->make(AuditService::class)->record('role.deleted', 'role', $id);
        return json_response(0, 'deleted');
    }

    private function syncPerms(int $roleId, array $permIds): void
    {
        Db::table('role_permissions')->where('role_id', $roleId)->delete();
        $unique = array_values(array_unique(array_map('intval', $permIds)));
        if (!$unique) return;
        $valid = Db::table('permissions')->whereIn('id', $unique)->column('id');
        $rows = array_map(fn ($pid) => [
            'role_id' => $roleId, 'permission_id' => (int)$pid,
            'granted_by' => (int)$this->request->userId,
        ], $valid);
        if ($rows) Db::table('role_permissions')->insertAll($rows);
    }
}
