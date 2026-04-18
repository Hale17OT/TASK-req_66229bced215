<?php
namespace app\service\workflow\transitions;

use app\service\workflow\StateMachine;

class SettlementMachine
{
    public static function make(): StateMachine
    {
        return new StateMachine('settlement', [
            'unpaid'                 => ['recorded_not_confirmed', 'exception'],
            'recorded_not_confirmed' => ['confirmed', 'exception'],
            'confirmed'              => ['partially_refunded', 'refunded', 'exception'],
            'partially_refunded'     => ['refunded', 'partially_refunded', 'exception'],
            'refunded'               => [],
            'exception'              => ['recorded_not_confirmed', 'confirmed'],
        ]);
    }
}
