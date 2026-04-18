<?php
namespace app\service\workflow\transitions;

use app\service\workflow\StateMachine;

class AttendanceCorrectionMachine
{
    public static function make(): StateMachine
    {
        return new StateMachine('attendance_correction', [
            'draft'      => ['submitted', 'cancelled'],
            'submitted'  => ['approved', 'rejected', 'withdrawn'],
            'approved'   => ['applied'],
            'rejected'   => [],
            'withdrawn'  => [],
            'applied'    => [],
            'cancelled'  => [],
        ]);
    }
}
