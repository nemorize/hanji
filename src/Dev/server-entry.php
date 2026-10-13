<?php

declare(strict_types=1);

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\TimeoutCancellation;
use Hanji\Dev\Protocol;
use Hanji\Dev\SocketHandler;
use Hanji\Support\Maps;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\Socket\connect;

require __DIR__ . '/bootstrap.php';
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(static function (int $event): void {});
}
$address = $argv[1] ?? '';
$statePath = $argv[2] ?? '';
$controlAddress = $argv[3] ?? '';
$token = getenv('HANJI_DEV_CONTROL_TOKEN');
if (!is_string($token) || strlen($token) !== 64) {
    throw new RuntimeException('Missing preview control credentials.');
}
$control = connect('tcp://' . $controlAddress, cancellation: new TimeoutCancellation(5));
$control->write($token . "\n");
$logger = new NullLogger();
$server = SocketHttpServer::createForDirectAccess($logger);
$server->expose($address);
$handler = new SocketHandler($server, $logger, Protocol::read($statePath));
$server->start($handler, new DefaultErrorHandler());
fwrite(STDOUT, "ready\n");
async(static function () use ($handler, $server, $control): void {
    $buffer = '';
    try {
        while (($chunk = $control->read()) !== null) {
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 1);
                $handler->update(Maps::validate(json_decode($line, true, 512, JSON_THROW_ON_ERROR), 'Preview update'));
            }
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'Preview control error: ' . $error->getMessage() . "\n");
    } finally {
        $control->close();
        $server->stop();
    }
})->ignore();
EventLoop::run();
