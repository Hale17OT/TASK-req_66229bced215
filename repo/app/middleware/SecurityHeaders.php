<?php
namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        return $response
            ->header([
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options'        => 'SAMEORIGIN',
                'Referrer-Policy'        => 'no-referrer',
                'X-XSS-Protection'       => '1; mode=block',
            ]);
    }
}
