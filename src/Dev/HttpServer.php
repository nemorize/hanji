<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\ConfigurationException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;

final class HttpServer
{
    private readonly Process $process;
    private readonly InputStream $input;
    public readonly int $port;

    public function __construct(private readonly string $host, int $port, string $session)
    {
        self::validate($host, $port);
        $this->port = $port === 0 ? self::freePort($host) : $port;
        $bind = self::address($host, $this->port);
        $probe = @stream_socket_server('tcp://' . $bind, $code, $error);
        if ($probe === false) {
            throw new BuildException('Preview address is unavailable: ' . $bind . '. ' . $error);
        }
        fclose($probe);
        $this->input = new InputStream();
        $this->process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/server-entry.php', $bind, $session . '/state.json'], input: $this->input, timeout: null);
        $this->process->start();
        $until = microtime(true) + 5;
        do {
            if (!$this->process->isRunning()) {
                throw new BuildException('Cannot start preview server at ' . $bind . ': ' . trim($this->process->getErrorOutput()));
            }
            $socket = @stream_socket_client('tcp://' . self::address($this->connectHost(), $this->port), $code, $error, 0.05);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->close();
        throw new BuildException('Preview server did not start at ' . $bind . '.');
    }

    public function url(Project $project): string
    {
        return 'http://' . self::address($this->connectHost(), $this->port) . $project->url('/');
    }

    public static function validate(string $host, int $port): void
    {
        if (($port < 0 || $port > 65535) || (filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/iD', $host))) {
            throw new ConfigurationException('serve requires a valid host and a port between 0 and 65535.');
        }
    }

    public function poll(): string
    {
        if (!$this->process->isRunning()) {
            throw new BuildException('Preview server stopped unexpectedly: ' . trim($this->process->getErrorOutput()));
        }
        $output = $this->process->getIncrementalErrorOutput();
        $this->process->clearErrorOutput();
        $this->process->clearOutput();
        return $output;
    }

    public function close(): void
    {
        $this->input->close();
        $this->process->stop(0.5);
    }

    /** @param array<string, mixed> $state */
    public function publish(array $state): void
    {
        $this->input->write(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        $this->process->isRunning();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connectHost(): string
    {
        return match ($this->host) {
            '0.0.0.0' => '127.0.0.1',
            '::' => '::1',
            default => $this->host,
        };
    }

    private static function address(string $host, int $port): string
    {
        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }

    private static function freePort(string $host): int
    {
        $socket = @stream_socket_server('tcp://' . self::address($host, 0), $code, $error);
        if ($socket === false) {
            throw new BuildException('Cannot bind preview host: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false || !preg_match('/:([0-9]+)$/D', $address, $matches)) {
            throw new BuildException('Cannot determine preview port.');
        }
        return (int) $matches[1];
    }
}
