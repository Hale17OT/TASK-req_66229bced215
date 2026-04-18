<?php
namespace app\model;

use think\Model;

class User extends Model
{
    protected $table = 'users';
    protected $autoWriteTimestamp = false;
    protected $hidden = ['password_hash'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'role_id', 'user_id');
    }

    public function scopes()
    {
        return $this->hasMany(UserScope::class, 'user_id', 'id');
    }

    public function isLocked(): bool
    {
        if ($this->status === 'locked') return true;
        if ($this->locked_until && strtotime((string)$this->locked_until) > time()) return true;
        return false;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isLocked();
    }

    public function hasGlobalScope(): bool
    {
        return $this->scopes()->where('is_global', 1)->count() > 0;
    }
}
