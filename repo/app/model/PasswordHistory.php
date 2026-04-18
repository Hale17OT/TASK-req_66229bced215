<?php
namespace app\model;

use think\Model;

class PasswordHistory extends Model
{
    protected $table = 'password_history';
    protected $autoWriteTimestamp = false;
}
