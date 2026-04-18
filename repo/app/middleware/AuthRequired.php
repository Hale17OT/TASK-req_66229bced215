<?php
namespace app\middleware;

use app\exception\AuthenticationException;
use app\model\User;
use app\service\auth\PasswordPolicy;
use app\service\auth\PermissionResolver;
use app\service\auth\SessionService;
use Closure;
use think\facade\Session;
use think\Request;
use think\Response;

class AuthRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int)Session::get('user_id', 0);
        $sid = (string)Session::getId();
        if ($userId <= 0 || $sid === '') {
            throw new AuthenticationException();
        }

        $sessions = SessionService::fromConfig();
        $sessionRow = $sessions->findValid($sid);
        if (!$sessionRow || (int)$sessionRow->user_id !== $userId) {
            Session::destroy();
            throw new AuthenticationException('Session expired or revoked');
        }

        $user = User::find($userId);
        if (!$user) {
            Session::destroy();
            throw new AuthenticationException('Account is not available');
        }
        if ($user->status === 'disabled' || $user->isLocked()) {
            Session::destroy();
            throw new AuthenticationException('Account is disabled or locked');
        }

        // Touch session activity
        $sessions->touch($sessionRow);

        // Stash on request for downstream
        $resolver = app()->make(PermissionResolver::class);
        $request->actingAs($user, $resolver->scopeFor((int)$user->id));

        // Mid-flight expiry promotion so the gate below handles both the
        // "already flagged" and "just aged out" cases uniformly.
        $policy = PasswordPolicy::fromConfig();
        if ($user->status === 'active' && $policy->isExpired($user)) {
            $user->status = 'password_expired';
            $user->save();
        }

        // password_expired users may only hit password-change / logout / me.
        // pathinfo() returns the path without leading slash in ThinkPHP.
        if ($user->status === 'password_expired'
            && !preg_match('#^api/v1/auth/(password|logout|me)#', $request->pathinfo())) {
            throw new AuthenticationException('Password rotation required');
        }

        return $next($request);
    }
}
