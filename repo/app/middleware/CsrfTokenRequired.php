<?php
namespace app\middleware;

use app\exception\AuthorizationException;
use Closure;
use think\facade\Cookie;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * Per-session CSRF token bound to the cookie `studio_csrf`.
 * Issued on first GET, required on POST/PUT/DELETE/PATCH via X-CSRF-Token header.
 */
class CsrfTokenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string)Session::get('csrf_token', '');
        if ($expected === '') {
            $expected = bin2hex(random_bytes(32));
            Session::set('csrf_token', $expected);
        }

        $method = strtoupper($request->method());
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $sent = (string)$request->header('X-CSRF-Token', '');
            if (!hash_equals($expected, $sent)) {
                throw new AuthorizationException('CSRF token missing or invalid');
            }
        }

        /** @var Response $response */
        $response = $next($request);
        // Surface the token via cookie for the JS helper
        Cookie::set('studio_csrf', $expected, [
            'expire' => 0, 'httponly' => false, 'samesite' => 'Lax',
        ]);
        return $response;
    }
}
