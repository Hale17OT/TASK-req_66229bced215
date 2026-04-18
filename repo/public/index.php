<?php
// +----------------------------------------------------------------------
// | Front controller — ThinkPHP 6.1
// +----------------------------------------------------------------------

namespace think;

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap and run the HTTP application
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
