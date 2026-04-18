<?php
// PHPUnit bootstrap — loads Composer autoload and ensures a ThinkPHP app is
// initialized at least once so facade aliases resolve during unit tests that
// don't instantiate an App themselves.

require __DIR__ . '/../vendor/autoload.php';

// Route the test suite at the test database.
putenv('DB_NAME=studio_console_test');
$_ENV['DB_NAME'] = 'studio_console_test';
$_SERVER['DB_NAME'] = 'studio_console_test';

$app = new \think\App();
$app->initialize();
