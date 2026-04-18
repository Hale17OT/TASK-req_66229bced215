<?php
namespace app\model;

use think\Model;

class UserScope extends Model
{
    protected $table = 'user_scope_assignments';
    protected $autoWriteTimestamp = false;
}
