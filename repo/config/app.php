<?php
// +----------------------------------------------------------------------
// | Application config
// +----------------------------------------------------------------------

return [
    // Application name
    'app_name'         => 'Studio Operations Console',
    // Application namespace
    'app_namespace'    => '',
    // Enable route
    'with_route'       => true,
    // Enable multi-app
    'auto_multi_app'   => false,
    // App mapping (multi-app)
    'app_map'          => [],
    // Domain bindings
    'domain_bind'      => [],
    // Disabled apps
    'deny_app_list'    => [],
    // Default timezone — overridden by env at runtime
    'default_timezone' => env('APP_TIMEZONE', 'UTC'),

    // Exception handling page template
    'exception_tmpl'   => '',

    'error_message'    => 'Server error.',
    'show_error_msg'   => env('APP_DEBUG', false),

    // Whitelist of HTTP exception classes that should always render their message
    'http_exception_template' => [],

    // Custom application config
    'studio' => [
        'password' => [
            'min_length'         => 12,
            'require_upper'      => true,
            'require_lower'      => true,
            'require_digit'      => true,
            'require_special'    => true,
            'history_window'     => 5,
            'rotation_days'      => 90,
        ],
        'session' => [
            'idle_timeout_seconds'  => 1800,    // 30 min
            'absolute_lifetime_sec' => 43200,   // 12 h
            'max_concurrent'        => 3,
        ],
        'lockout' => [
            'fail_threshold'   => 5,
            'window_seconds'   => 900,      // 15 min
            'cooldown_seconds' => 1800,     // 30 min
        ],
        'attachments' => [
            'allowed_mime' => [
                'application/pdf'  => 'pdf',
                'image/jpeg'       => 'jpg',
                'image/png'        => 'png',
            ],
            'max_bytes'    => 10 * 1024 * 1024, // 10 MB
            'max_per_request' => 5,
            'storage_root' => '/var/www/html/storage/attachments',
        ],
        'exports' => [
            'storage_root'    => '/var/www/html/storage/exports',
            'max_rows'        => 100000,
            'expiry_days'     => 14,
        ],
        'idempotency' => [
            'ttl_seconds'     => 86400,     // 24 h
        ],
        'audit' => [
            'retention_years'        => 7,
            'login_attempt_days'     => 365,
            'session_history_days'   => 180,
            'draft_recovery_days'    => 7,
        ],
        'reimbursement' => [
            'reason_min_chars_reject'   => 10,
            'reason_min_chars_override' => 15,
        ],
    ],
];
