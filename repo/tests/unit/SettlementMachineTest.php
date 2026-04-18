<?php
namespace Tests\unit;

use app\exception\IllegalStateTransitionException;
use app\service\workflow\transitions\SettlementMachine;
use PHPUnit\Framework\TestCase;

class SettlementMachineTest extends TestCase
{
    public function testHappyPathToRefund(): void
    {
        $m = SettlementMachine::make();
        $m->assert('unpaid', 'recorded_not_confirmed');
        $m->assert('recorded_not_confirmed', 'confirmed');
        $m->assert('confirmed', 'partially_refunded');
        $m->assert('partially_refunded', 'refunded');
        $this->assertTrue(true);
    }

    public function testCannotConfirmFromUnpaidDirectly(): void
    {
        $m = SettlementMachine::make();
        $this->expectException(IllegalStateTransitionException::class);
        $m->assert('unpaid', 'confirmed');
    }
}
