<?php
namespace app\model;

use think\Model;

class ReconciliationRun extends Model
{
    protected $table = 'reconciliation_runs';
    protected $autoWriteTimestamp = false;
    protected $type = ['summary_json' => 'json'];
}
