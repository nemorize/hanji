<?php

declare(strict_types=1);

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Hanji\Dev\Protocol;
use Hanji\Dev\SocketHandler;
use Hanji\Support\Maps;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\ByteStream\getStdin;

require __DIR__ . '/bootstrap.php';
$address = $argv[1] ?? '';
$statePath = $argv[2] ?? '';
$logger = new NullLogger();
$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose($address);
$handler = new SocketHandler($server, $logger, Protocol::read($statePath));
$server->start($handler, new DefaultErrorHandler());
async(static function () use ($handler, $server): void {
    $buffer = '';
    $input = getStdin();
    while (($chunk = $input->read()) !== null) {
        $buffer .= $chunk;
        while (($end = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $end);
            $buffer = substr($buffer, $end + 1);
            $handler->update(Maps::validate(json_decode($line, true, 512, JSON_THROW_ON_ERROR), 'Preview update'));
        }
    }
    $server->stop();
})->ignore();
EventLoop::run();
