<?php
namespace app\model;

use think\Model;

class ScheduleEntry extends Model
{
    protected $table = 'schedule_entries';
    protected $autoWriteTimestamp = false;
}
