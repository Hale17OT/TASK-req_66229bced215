<?php
namespace app\middleware;

use app\ExceptionHandle;
use app\exception\BusinessException;
use app\service\idempotency\IdempotencyService;
use Closure;
use Throwable;
use think\Request;
use think\Response;

class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return $next($request);

        $key = (string)$request->header('Idempotency-Key', '');
        if ($key === '') {
            // Required for unsafe writes — protects against replay/duplicate
            throw new BusinessException('Idempotency-Key header is required for this endpoint', 40010, 400);
        }
        if (strlen($key) > 128 || !preg_match('/\A[A-Za-z0-9_\-:.]+\z/', $key)) {
            throw new BusinessException('Idempotency-Key has invalid format', 40011, 400);
        }

        $svc = app()->make(IdempotencyService::class);
        $body = $request->getContent() ?: '';
        $userId = $request->userId ?? null;

        $look = $svc->lookup($key, $userId, $method, $request->pathinfo(), $body);

        if ($look['state'] === 'replay') {
            $resp = response($look['body'], $look['status'])->contentType('application/json');
            $resp->header(['X-Idempotent-Replay' => '1']);
            return $resp;
        }
        if ($look['state'] === 'in_flight') {
            throw new BusinessException('Concurrent retry of in-flight request', 40912, 409);
        }

        $svc->reserve($key, $userId, $method, $request->pathinfo(), $body);

        // Persist a terminal state on BOTH success and failure paths so a
        // same-key retry always replays deterministically instead of being
        // stranded in the `in_flight` guard. The error branch renders the
        // throwable through the app's exception handler so the cached body
        // matches what the client would otherwise receive, then re-throws so
        // the framework still reports/logs the exception normally.
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $e) {
            try {
                $errResp = app()->make(ExceptionHandle::class)->render($request, $e);
                $svc->complete($key, $userId, $errResp->getCode(), (string)$errResp->getContent());
            } catch (Throwable $_) {
                // Persist failure is best-effort; the original exception is
                // what the caller must still see.
            }
            throw $e;
        }
        $svc->complete($key, $userId, $response->getCode(), (string)$response->getContent());
        return $response;
    }
}
