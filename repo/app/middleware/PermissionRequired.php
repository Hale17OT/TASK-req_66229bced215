<?php
namespace app\middleware;

use app\exception\AuthorizationException;
use app\service\auth\PermissionResolver;
use Closure;
use think\Request;
use think\Response;

class PermissionRequired
{
    /**
     * Usage in route: ->middleware(['rbac:reimbursement.approve'])
     * Multiple permissions OR'd with '|' :  ->middleware(['rbac:perm.a|perm.b'])
     */
    public function handle(Request $request, Closure $next, string $perms = ''): Response
    {
        if (!$request->userId) throw new AuthorizationException('Unauthenticated');
        $required = array_filter(explode('|', $perms));
        if (!$required) return $next($request);
        $resolver = app()->make(PermissionResolver::class);
        if (!$resolver->hasAny($request->userId, $required)) {
            throw new AuthorizationException('Missing required permission: ' . implode(' | ', $required));
        }
        return $next($request);
    }
}
