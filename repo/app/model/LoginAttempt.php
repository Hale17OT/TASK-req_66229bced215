<?php
namespace app\model;

use think\Model;

class LoginAttempt extends Model
{
    protected $table = 'login_attempts';
    protected $autoWriteTimestamp = false;
}
