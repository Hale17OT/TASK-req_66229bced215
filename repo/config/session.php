<?php
// +----------------------------------------------------------------------
// | Session config — server-side, file driver, 30 min idle / 12 h absolute
// +----------------------------------------------------------------------

return [
    'name'           => 'STUDIOSESSID',
    'type'           => 'file',
    'expire'         => 1800,
    'use_lock'       => true,
    'prefix'         => 'studio_',
    'serialize'      => [],
    'auto_start'     => true,
    'cookie_lifetime' => 0,    // session cookie
    'httponly'       => true,
    'secure'         => false, // set true behind TLS reverse proxy
    'samesite'       => 'Lax',
    'path'           => app()->getRuntimePath() . 'session/',
];
