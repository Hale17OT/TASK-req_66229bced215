<?php
namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * Placeholder hook for cross-cutting audit. Most audit writes happen in services
 * (where the before/after state is known); this middleware is a seam for adding
 * generic request-level audit if needed later.
 */
class AuditTrail
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
