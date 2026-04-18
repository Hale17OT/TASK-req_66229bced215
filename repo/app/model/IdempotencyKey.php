<?php
namespace app\model;

use think\Model;

class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';
    protected $autoWriteTimestamp = false;
}
