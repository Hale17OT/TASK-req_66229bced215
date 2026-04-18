<?php
namespace Tests\unit;

use app\exception\IllegalStateTransitionException;
use app\service\workflow\transitions\ReimbursementMachine;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    public function testReimbursementHappyPath(): void
    {
        $m = ReimbursementMachine::make();
        $m->assert('draft', 'submitted');
        $m->assert('submitted', 'under_review');
        $m->assert('under_review', 'approved');
        $m->assert('approved', 'settlement_pending');
        $m->assert('settlement_pending', 'settled');
        $this->assertTrue(true);
    }

    public function testReimbursementOverridePath(): void
    {
        $m = ReimbursementMachine::make();
        $m->assert('submitted', 'pending_override_review');
        $m->assert('pending_override_review', 'under_review');
        $this->assertTrue(true);
    }

    public function testReimbursementIllegalTransitionThrows(): void
    {
        $m = ReimbursementMachine::make();
        $this->expectException(IllegalStateTransitionException::class);
        $m->assert('draft', 'approved'); // skipping submit — illegal
    }

    public function testTerminalRejectedHasNoNext(): void
    {
        $m = ReimbursementMachine::make();
        $this->assertSame([], $m->next('rejected'));
        $this->assertSame([], $m->next('refunded'));
    }
}
