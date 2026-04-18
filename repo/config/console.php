<?php
// Custom console commands registered with the framework
return [
    'commands' => [
        'jobs:run'    => \app\command\JobsRun::class,
        'jobs:status' => \app\command\JobsStatus::class,
        'db:seed'     => \app\command\DbSeed::class,
    ],
];
