<?php
namespace app\service\workflow;

use app\exception\IllegalStateTransitionException;

/**
 * Table-driven state machine. Each domain (attendance correction, schedule
 * adjustment, reimbursement, settlement) defines its transition map and uses
 * StateMachine::assert() before mutating state. Illegal transitions throw a
 * 409-mapped exception that is also audited.
 */
class StateMachine
{
    public function __construct(private string $entity, private array $transitions)
    {
    }

    /** @return string[] reachable states from $from */
    public function next(string $from): array
    {
        return $this->transitions[$from] ?? [];
    }

    public function permits(string $from, string $to): bool
    {
        return in_array($to, $this->next($from), true);
    }

    public function assert(string $from, string $to): void
    {
        if (!$this->permits($from, $to)) {
            throw new IllegalStateTransitionException($this->entity, $from, $to);
        }
    }
}
