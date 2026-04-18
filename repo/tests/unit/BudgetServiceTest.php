<?php
namespace Tests\unit;

use app\service\money\Money;
use PHPUnit\Framework\TestCase;

class BudgetServiceTest extends TestCase
{
    public function testAvailableFormulaUsesDecimalSafeMath(): void
    {
        // available = cap - consumed - active
        $cap = Money::of('25000.00');
        $consumed = Money::of('1234.56');
        $active = Money::of('789.45');
        $avail = $cap->sub($consumed)->sub($active);
        $this->assertSame('22975.99', (string)$avail);
        $this->assertFalse($avail->isNegative());
    }

    public function testOverCapDetected(): void
    {
        $cap = Money::of('100.00');
        $remaining = $cap->sub(Money::of('80.00'))->sub(Money::of('25.00'));
        $this->assertTrue($remaining->isNegative());
        $this->assertSame('-5.00', (string)$remaining);
    }
}
