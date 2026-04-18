<?php
return [
    'default' => 'local',
    'disks'   => [
        'local' => [
            'type' => 'local',
            'root' => app()->getRootPath() . 'storage',
        ],
        'attachments' => [
            'type' => 'local',
            'root' => app()->getRootPath() . 'storage/attachments',
        ],
        'exports' => [
            'type' => 'local',
            'root' => app()->getRootPath() . 'storage/exports',
        ],
    ],
];
