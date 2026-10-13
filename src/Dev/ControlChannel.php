<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;

final class ControlChannel
{
    /** @var resource|null */
    private $listener = null;
    /** @var resource|null */
    private $connection = null;
    /** @var list<array{socket: resource, buffer: string, expires: float}> */
    private array $pending = [];
    private string $buffer = '';
    public readonly string $address;
    public readonly string $token;

    public function __construct()
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        if ($listener === false) {
            throw new BuildException('Cannot create the preview control channel: ' . $error);
        }
        $address = stream_socket_get_name($listener, false);
        if ($address === false) {
            fclose($listener);
            throw new BuildException('Cannot determine the preview control address.');
        }
        stream_set_blocking($listener, false);
        $this->listener = $listener;
        $this->address = $address;
        $this->token = bin2hex(random_bytes(32));
    }

    public function accept(): bool
    {
        if ($this->connection !== null) {
            return true;
        }
        $listener = $this->listener;
        if ($listener === null) {
            return false;
        }
        for ($i = 0; $i < 8; ++$i) {
            $socket = @stream_socket_accept($listener, 0);
            if ($socket === false) {
                break;
            }
            stream_set_blocking($socket, false);
            $this->pending[] = ['socket' => $socket, 'buffer' => '', 'expires' => microtime(true) + 1];
        }
        $pending = [];
        foreach ($this->pending as $candidate) {
            $chunk = @fread($candidate['socket'], 128);
            $candidate['buffer'] .= $chunk === false ? '' : $chunk;
            if ($this->connection !== null || $chunk === false || feof($candidate['socket']) || microtime(true) > $candidate['expires'] || strlen($candidate['buffer']) > 65) {
                fclose($candidate['socket']);
            } elseif (str_contains($candidate['buffer'], "\n")) {
                if (hash_equals($this->token . "\n", $candidate['buffer'])) {
                    $this->connection = $candidate['socket'];
                    fclose($listener);
                    $this->listener = null;
                } else {
                    fclose($candidate['socket']);
                }
            } else {
                $pending[] = $candidate;
            }
        }
        if ($this->connection !== null) {
            foreach ($pending as $candidate) {
                fclose($candidate['socket']);
            }
            $pending = [];
        }
        $this->pending = $pending;
        return $this->connection !== null;
    }

    /** @param array<string, mixed> $state */
    public function send(array $state): void
    {
        $this->buffer .= json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $this->poll();
    }

    public function poll(): void
    {
        $connection = $this->connection;
        if ($connection === null) {
            throw new BuildException('The preview control channel is not connected.');
        }
        $incoming = @fread($connection, 1);
        if ($incoming === false || $incoming !== '' || feof($connection)) {
            throw new BuildException('The preview control channel disconnected.');
        }
        if ($this->buffer !== '') {
            $written = @fwrite($connection, $this->buffer);
            if ($written === false) {
                throw new BuildException('Cannot send a preview update.');
            }
            $this->buffer = substr($this->buffer, $written);
        }
    }

    public function close(): void
    {
        foreach ([$this->listener, $this->connection, ...array_column($this->pending, 'socket')] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->listener = null;
        $this->connection = null;
        $this->pending = [];
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }
}
