<?php
namespace app\model;

use think\Model;

class ReconciliationException extends Model
{
    protected $table = 'reconciliation_exceptions';
    protected $autoWriteTimestamp = false;
    protected $type = ['detail_json' => 'json'];
}
