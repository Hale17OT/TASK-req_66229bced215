<?php
namespace app\exception;

class AuthorizationException extends BusinessException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 40300, 403);
    }
}
