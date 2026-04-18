<?php
// Middleware aliases and priority ordering.
//  - `alias` — short names used in route definitions: ->middleware(['auth', 'rbac:perm.a'])
//  - `priority` — relative ordering (earlier = runs first) when multiple apply.
// Global middleware is declared in app/middleware.php, not here.

return [
    'alias' => [
        'auth'       => \app\middleware\AuthRequired::class,
        'rbac'       => \app\middleware\PermissionRequired::class,
        'scope'      => \app\middleware\ScopeFilter::class,
        'csrf'       => \app\middleware\CsrfTokenRequired::class,
        'idempotent' => \app\middleware\Idempotency::class,
        'audit'      => \app\middleware\AuditTrail::class,
    ],

    'priority' => [
        \think\middleware\SessionInit::class,
        \app\middleware\RequestLogger::class,
        \app\middleware\SecurityHeaders::class,
        \app\middleware\AuthRequired::class,
        \app\middleware\CsrfTokenRequired::class,
        \app\middleware\PermissionRequired::class,
        \app\middleware\ScopeFilter::class,
        \app\middleware\Idempotency::class,
        \app\middleware\AuditTrail::class,
    ],
];
