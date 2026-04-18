<?php
// Web (page) routes — Layui HTML pages live in /public/pages/*.html
// served as static files by nginx. ThinkPHP only redirects "/" → login or shell.
use think\facade\Route;

Route::get('/', function () {
    return redirect('/pages/login.html');
});

Route::get('/healthz', function () {
    return json(['status' => 'ok', 'ts' => gmdate('c')]);
});
