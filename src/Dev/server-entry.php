<?php

declare(strict_types=1);

use Hanji\Dev\Protocol;
use Hanji\Dev\Router;

require __DIR__ . '/bootstrap.php';
$state = getenv('HANJI_DEV_STATE');
if (!is_string($state) || $state === '') {
    http_response_code(503);
    exit;
}
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!is_string($uri) || !is_string($method) || !is_string($host)) {
    http_response_code(400);
    exit;
}
(new Router(Protocol::read($state)))->handle($uri, $method, $host)->send($method === 'HEAD');
