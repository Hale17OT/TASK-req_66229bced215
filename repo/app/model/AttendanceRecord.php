<?php
namespace app\model;

use think\Model;

class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';
    protected $autoWriteTimestamp = false;
}
