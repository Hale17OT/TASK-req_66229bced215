<?php
namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * No-op middleware that simply documents a route requires scope-aware queries.
 * The actual filtering happens in services using app\service\auth\ScopeFilter.
 */
class ScopeFilter
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
