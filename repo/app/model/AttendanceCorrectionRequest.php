<?php
namespace app\model;

use think\Model;

class AttendanceCorrectionRequest extends Model
{
    protected $table = 'attendance_correction_requests';
    protected $autoWriteTimestamp = false;
    protected $type = ['proposed_payload_json' => 'json'];
}
