<?php
return [
    'default'  => env('LOG_CHANNEL', 'file'),
    'channels' => [
        'file' => [
            'type'           => 'File',
            'path'           => '',
            'apart_level'    => ['error', 'warning'],
            'max_files'      => 30,
            'json'           => false,
            'processor'      => null,
            'close'          => false,
            'format'         => '[%s][%s] %s',
        ],
    ],
];
