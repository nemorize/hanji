<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Amp\Http\Server\HttpServer as Server;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response as HttpResponse;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use Psr\Log\LoggerInterface;

final class SocketHandler implements RequestHandler, WebsocketClientHandler
{
    private readonly Websocket $socket;
    private readonly WebsocketClientGateway $clients;

    /** @param array<string, mixed> $state */
    public function __construct(Server $server, LoggerInterface $logger, private array $state)
    {
        $this->clients = new WebsocketClientGateway();
        $this->socket = new Websocket($server, $logger, new Rfc6455Acceptor(), $this);
    }

    public function handleRequest(Request $request): HttpResponse
    {
        $router = new Router($this->state);
        $host = $request->getHeader('host') ?? '';
        if (!$router->allowedHost($host)) {
            return (new Response(403, body: 'This host is not allowed.'))->http();
        }
        if ($request->getUri()->getPath() === Router::SOCKET) {
            if ($request->getHeader('origin') !== 'http://' . $host) {
                return (new Response(403, body: 'This origin is not allowed.'))->http();
            }
            return $this->socket->handleRequest($request);
        }
        $response = $router->handle((string) $request->getUri(), $request->getMethod(), $host);
        fwrite(STDERR, $request->getMethod() . ' ' . $request->getUri()->getPath() . ' ' . $response->status . "\n");
        return $response->http();
    }

    public function handleClient(WebsocketClient $client, Request $request, HttpResponse $response): void
    {
        $this->clients->addClient($client);
        $this->clients->sendText($this->message(), $client->getId())->await();
        if ($client->receive() !== null) {
            $client->close(WebsocketCloseCode::POLICY_VIOLATION, 'The preview channel only sends updates.');
        }
    }

    /** @param array<string, mixed> $state */
    public function update(array $state): void
    {
        $this->state = $state;
        $this->clients->broadcastText($this->message())->ignore();
    }

    private function message(): string
    {
        return json_encode(Router::publicState($this->state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
