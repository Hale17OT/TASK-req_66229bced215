<?php
namespace app\service\money;

use InvalidArgumentException;

/**
 * Decimal-safe money value (spec: decimal(18,2), bcmath, JSON-as-string).
 * Immutable; arithmetic returns new instances.
 */
final class Money
{
    private string $amount; // canonical 2-decimal string

    private function __construct(string $amount)
    {
        $this->amount = bcadd($amount, '0', 2);
    }

    public static function of(int|float|string $v): self
    {
        $s = is_string($v) ? trim($v) : (string)$v;
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new InvalidArgumentException("invalid money: {$s}");
        }
        return new self($s);
    }

    public static function zero(): self { return new self('0'); }

    public function add(Money $o): Money     { return new self(bcadd($this->amount, $o->amount, 2)); }
    public function sub(Money $o): Money     { return new self(bcsub($this->amount, $o->amount, 2)); }
    public function gt(Money $o): bool       { return bccomp($this->amount, $o->amount, 2) === 1; }
    public function gte(Money $o): bool      { return bccomp($this->amount, $o->amount, 2) >= 0; }
    public function lt(Money $o): bool       { return bccomp($this->amount, $o->amount, 2) === -1; }
    public function lte(Money $o): bool      { return bccomp($this->amount, $o->amount, 2) <= 0; }
    public function eq(Money $o): bool       { return bccomp($this->amount, $o->amount, 2) === 0; }
    public function isZero(): bool           { return bccomp($this->amount, '0', 2) === 0; }
    public function isPositive(): bool       { return bccomp($this->amount, '0', 2) === 1; }
    public function isNegative(): bool       { return bccomp($this->amount, '0', 2) === -1; }
    public function neg(): Money             { return new self(bcmul($this->amount, '-1', 2)); }

    public function toString(): string       { return $this->amount; }
    public function __toString(): string     { return $this->amount; }
}
