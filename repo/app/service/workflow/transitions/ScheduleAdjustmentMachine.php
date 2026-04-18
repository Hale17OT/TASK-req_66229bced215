<?php
namespace app\service\workflow\transitions;

use app\service\workflow\StateMachine;

class ScheduleAdjustmentMachine
{
    public static function make(): StateMachine
    {
        return new StateMachine('schedule_adjustment', [
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
