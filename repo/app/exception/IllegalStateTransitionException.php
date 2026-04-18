<?php
namespace app\exception;

class IllegalStateTransitionException extends BusinessException
{
    public function __construct(string $entity, string $from, string $to)
    {
        parent::__construct(
            "Illegal state transition for {$entity}: {$from} → {$to}",
            40900,
            409,
            ['from' => $from, 'to' => $to, 'entity' => $entity]
        );
    }
}
