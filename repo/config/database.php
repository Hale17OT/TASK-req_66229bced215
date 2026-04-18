<?php
// +----------------------------------------------------------------------
// | Database config — single MySQL connection, pulled from env
// +----------------------------------------------------------------------

return [
    'default'     => env('DB_DRIVER', 'mysql'),
    'connections' => [
        'mysql' => [
            'type'     => 'mysql',
            'hostname' => env('DB_HOST', 'db'),
            'database' => env('DB_NAME', 'studio_console'),
            'username' => env('DB_USER', 'studio'),
            'password' => env('DB_PASS', 'studio_change_me'),
            'hostport' => env('DB_PORT', '3306'),
            'charset'  => 'utf8mb4',
            'prefix'   => '',
            'debug'    => env('APP_DEBUG', false),
            'deploy'   => 0,
            'rw_separate' => false,
            'master_num'  => 1,
            'slave_no'    => '',
            'fields_strict' => true,
            'trigger_sql'   => true,
            'fields_cache'  => false,
            'schema_cache_path' => app()->getRuntimePath() . 'schema/',
            'params'        => [
                \PDO::ATTR_ERRMODE         => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_PERSISTENT      => false,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO', time_zone='+00:00'",
            ],
        ],
    ],
];
