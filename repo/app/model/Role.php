<?php
namespace app\model;

use think\Model;

class Role extends Model
{
    protected $table = 'roles';
    protected $autoWriteTimestamp = false;

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'permission_id', 'role_id');
    }
}
