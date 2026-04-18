<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

class ExceptionHandle extends Handle
{
    /** Exception classes that should not be reported to the application log. */
    protected $ignoreReport = [
        ValidateException::class,
        \app\exception\BusinessException::class,
        \app\exception\IllegalStateTransitionException::class,
        \app\exception\AuthorizationException::class,
    ];

    public function render($request, Throwable $e): Response
    {
        if ($e instanceof ValidateException) {
            return json_response(40000, $e->getMessage(), null, $e->getError(), 422);
        }
        if ($e instanceof \app\exception\AuthenticationException) {
            return json_response(40100, $e->getMessage() ?: 'Authentication required', null, [], 401);
        }
        if ($e instanceof \app\exception\AuthorizationException) {
            return json_response(40300, $e->getMessage() ?: 'Forbidden', null, [], 403);
        }
        if ($e instanceof \app\exception\IllegalStateTransitionException) {
            return json_response(40900, $e->getMessage() ?: 'Illegal state transition', null, [], 409);
        }
        if ($e instanceof \app\exception\IdempotencyConflictException) {
            return json_response(40901, $e->getMessage() ?: 'Idempotency key reuse with different payload', null, [], 409);
        }
        if ($e instanceof \app\exception\BusinessException) {
            return json_response($e->getCode() ?: 40000, $e->getMessage(), null, $e->errors(), $e->httpStatus());
        }
        if ($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) {
            return json_response(40400, 'Resource not found', null, [], 404);
        }
        if ($e instanceof HttpException) {
            return json_response($e->getStatusCode() * 100, $e->getMessage(), null, [], $e->getStatusCode());
        }

        // Other unexpected exceptions
        if (env('APP_DEBUG', false)) {
            return parent::render($request, $e);
        }
        return json_response(50000, 'Server error', null, [], 500);
    }
}
