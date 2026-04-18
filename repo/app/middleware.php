<?php
// Global middleware for this app (single-app mode). In TP6.1, app/middleware.php
// is the canonical place for app-global middleware — each entry is a class name.
// Middleware aliases used by routes are declared in config/middleware.php.
return [
    \think\middleware\SessionInit::class,
    \app\middleware\RequestLogger::class,
    \app\middleware\SecurityHeaders::class,
];
