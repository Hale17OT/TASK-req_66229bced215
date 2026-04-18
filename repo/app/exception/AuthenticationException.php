<?php
namespace app\exception;

class AuthenticationException extends BusinessException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message, 40100, 401);
    }
}
