<?php
namespace Tests\unit;

use app\service\money\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testParseAndStringify(): void
    {
        $this->assertSame('25000.00', (string)Money::of('25000'));
        $this->assertSame('25000.00', (string)Money::of(25000));
        $this->assertSame('0.00',     (string)Money::of('0.005')); // bcmath truncates to 2 decimals (toward zero)
        $this->assertSame('-3.14',    (string)Money::of('-3.14'));
    }

    public function testArithmeticIsExact(): void
    {
        $a = Money::of('0.10');
        $b = Money::of('0.20');
        $sum = $a->add($b);
        $this->assertSame('0.30', (string)$sum, 'no float drift');
    }

    public function testComparisons(): void
    {
        $a = Money::of('100.00');
        $b = Money::of('100.01');
        $this->assertTrue($b->gt($a));
        $this->assertTrue($a->lt($b));
        $this->assertTrue($a->eq(Money::of('100')));
        $this->assertFalse($a->eq($b));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of('not money');
    }
}
