<?php
namespace app\model;

use think\Model;

class UserSession extends Model
{
    protected $table = 'user_sessions';
    protected $autoWriteTimestamp = false;
}
