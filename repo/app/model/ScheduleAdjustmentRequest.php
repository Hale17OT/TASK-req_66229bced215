<?php
namespace app\model;

use think\Model;

class ScheduleAdjustmentRequest extends Model
{
    protected $table = 'schedule_adjustment_requests';
    protected $autoWriteTimestamp = false;
    protected $type = ['proposed_changes_json' => 'json'];
}
