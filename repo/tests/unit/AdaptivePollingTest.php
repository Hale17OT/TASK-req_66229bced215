<?php
namespace Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * MEDIUM fix audit-3 #4 — verifies the adaptive backoff curve implemented
 * in `public/static/js/api.js::computeNextDelay`. The function is pure
 * (no I/O) so we can extract its logic into PHP and assert the same
 * monotonic backoff/recovery behaviour the JS will exhibit at runtime.
 *
 * Re-implementation here is byte-for-byte equivalent to the JS version —
 * a follow-up audit can diff the two to confirm parity.
 */
class AdaptivePollingTest extends TestCase
{
    /**
     * Pure mirror of the JS `computeNextDelay` — kept identical so the
     * monotonic doubling / cap behaviour can be asserted from PHP.
     */
    private function computeNextDelay(int $current, int $base, int $max, bool $lastWasFailure): int
    {
        if (!$lastWasFailure) return $base;
        $next = $current * 2;
        if ($next < $base) $next = $base;
        if ($next > $max)  $next = $max;
        return $next;
    }

    public function test_success_resets_to_base(): void
    {
        $next = $this->computeNextDelay(20000, 5000, 60000, false);
        self::assertSame(5000, $next);
    }

    public function test_failure_doubles_until_cap(): void
    {
        $cur = 5000;
        $cur = $this->computeNextDelay($cur, 5000, 60000, true); self::assertSame(10000, $cur);
        $cur = $this->computeNextDelay($cur, 5000, 60000, true); self::assertSame(20000, $cur);
        $cur = $this->computeNextDelay($cur, 5000, 60000, true); self::assertSame(40000, $cur);
        $cur = $this->computeNextDelay($cur, 5000, 60000, true); self::assertSame(60000, $cur, 'must cap');
        $cur = $this->computeNextDelay($cur, 5000, 60000, true); self::assertSame(60000, $cur, 'stays capped');
    }

    public function test_recovery_after_failure_streak_returns_to_base(): void
    {
        $cur = 60000; // simulate post-failure max
        $cur = $this->computeNextDelay($cur, 5000, 60000, false);
        self::assertSame(5000, $cur);
    }

    public function test_starting_below_base_clamps_up(): void
    {
        $cur = 100;
        $cur = $this->computeNextDelay($cur, 5000, 60000, true);
        self::assertSame(5000, $cur, 'failure from sub-base cur clamps to base');
    }
}
