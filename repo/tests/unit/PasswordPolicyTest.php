<?php
namespace Tests\unit;

use app\service\auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    private function policy(): PasswordPolicy
    {
        return new PasswordPolicy([
            'min_length'      => 12,
            'require_upper'   => true,
            'require_lower'   => true,
            'require_digit'   => true,
            'require_special' => true,
            'history_window'  => 5,
            'rotation_days'   => 90,
        ]);
    }

    public function testTooShort(): void
    {
        $errs = $this->policy()->validate('Aa1!aa');
        $this->assertNotEmpty($errs);
        $this->assertStringContainsString('at least 12', $errs[0]);
    }

    public function testMissingComplexityClasses(): void
    {
        $errs = $this->policy()->validate('alllowercaselong');
        $this->assertGreaterThan(1, count($errs));
    }

    public function testAcceptsValid(): void
    {
        $this->assertSame([], $this->policy()->validate('Strong!Pass#2026'));
    }

    public function testHashIsArgon2id(): void
    {
        $h = $this->policy()->hash('Strong!Pass#2026');
        $this->assertStringStartsWith('$argon2id$', $h);
        $this->assertTrue($this->policy()->verify('Strong!Pass#2026', $h));
        $this->assertFalse($this->policy()->verify('wrong', $h));
    }
}
