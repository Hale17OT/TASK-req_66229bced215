<?php
namespace app\service\auth;

use app\model\User;
use think\facade\Db;

/**
 * Resolves the effective permission set + scope for a user.
 * Cached per-request (one query per check).
 */
class PermissionResolver
{
    private array $permCache = [];
    private array $scopeCache = [];

    public function permissionsFor(int $userId): array
    {
        if (isset($this->permCache[$userId])) return $this->permCache[$userId];
        $rows = Db::table('user_roles')->alias('ur')
            ->leftJoin('role_permissions rp', 'rp.role_id = ur.role_id')
            ->leftJoin('permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)
            ->whereNotNull('p.key')
            ->distinct(true)
            ->column('p.key');
        return $this->permCache[$userId] = array_values($rows ?: []);
    }

    public function rolesFor(int $userId): array
    {
        return Db::table('user_roles')->alias('ur')
            ->leftJoin('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->column('r.key');
    }

    public function scopeFor(int $userId): array
    {
        if (isset($this->scopeCache[$userId])) return $this->scopeCache[$userId];
        $rows = Db::table('user_scope_assignments')
            ->where('user_id', $userId)
            ->select()->toArray();
        $isGlobal = false;
        $locations = [];
        $departments = [];
        foreach ($rows as $r) {
            if ($r['is_global']) $isGlobal = true;
            if (!empty($r['location_id'])) $locations[] = (int)$r['location_id'];
            if (!empty($r['department_id'])) $departments[] = (int)$r['department_id'];
        }
        return $this->scopeCache[$userId] = [
            'global'      => $isGlobal,
            'locations'   => array_values(array_unique($locations)),
            'departments' => array_values(array_unique($departments)),
        ];
    }

    /** Returns true if the user has ANY of the given permissions. */
    public function hasAny(int $userId, array $perms): bool
    {
        $set = array_flip($this->permissionsFor($userId));
        foreach ($perms as $p) if (isset($set[$p])) return true;
        return false;
    }

    public function has(int $userId, string $perm): bool
    {
        return $this->hasAny($userId, [$perm]);
    }
}
