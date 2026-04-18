<?php
namespace app\exception;

use RuntimeException;

class BusinessException extends RuntimeException
{
    protected int $httpStatus = 400;
    protected array $errors = [];

    public function __construct(string $message = '', int $code = 40000, int $httpStatus = 400, array $errors = [])
    {
        parent::__construct($message, $code);
        $this->httpStatus = $httpStatus;
        $this->errors = $errors;
    }

    public function httpStatus(): int { return $this->httpStatus; }
    public function errors(): array   { return $this->errors; }
}
