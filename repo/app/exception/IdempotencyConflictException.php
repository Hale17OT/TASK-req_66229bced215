<?php
namespace app\exception;

class IdempotencyConflictException extends BusinessException
{
    public function __construct(string $key)
    {
        parent::__construct(
            "Idempotency key '{$key}' was previously used with a different payload",
            40901,
            409
        );
    }
}
