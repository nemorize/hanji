<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\BuildException;
use Hanji\Dev\ControlChannel;
use PHPUnit\Framework\TestCase;

final class ControlChannelTest extends TestCase
{
    private ControlChannel $channel;
    /** @var list<resource> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->channel = new ControlChannel();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
        $this->channel->close();
    }

    public function testChannelAuthenticatesFragmentedCredentialsAndRejectsOtherConnections(): void
    {
        self::assertStringStartsWith('127.0.0.1:', $this->channel->address);
        $wrong = $this->connect();
        fwrite($wrong, str_repeat('0', 64) . "\n");
        self::assertFalse($this->channel->accept());
        $connection = $this->connect();
        fwrite($connection, substr($this->channel->token, 0, 32));
        self::assertFalse($this->channel->accept());
        fwrite($connection, substr($this->channel->token, 32) . "\n");
        $this->authenticate();
        $this->channel->send(['status' => 'ready', 'revision' => 1]);
        $message = $this->receive($connection);
        self::assertSame(['status' => 'ready', 'revision' => 1], json_decode($message, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testChannelPreservesLargeUpdatesAndTheirOrderAcrossPartialWrites(): void
    {
        $connection = $this->connect();
        fwrite($connection, $this->channel->token . "\n");
        $this->authenticate();
        $payload = str_repeat('한지', 300000);
        $this->channel->send(['revision' => 1, 'content' => $payload]);
        $this->channel->send(['revision' => 2]);
        $messages = $this->receive($connection, 2);
        $lines = explode("\n", rtrim($messages, "\n"));
        self::assertSame(['revision' => 1, 'content' => $payload], json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['revision' => 2], json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testChannelDetectsPeerExitAndReleasesTheListener(): void
    {
        $connection = $this->connect();
        fwrite($connection, $this->channel->token . "\n");
        $this->authenticate();
        fclose($connection);
        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('disconnected');
        $until = microtime(true) + 2;
        do {
            $this->channel->poll();
            usleep(1000);
        } while (microtime(true) < $until);
        self::fail('Control channel did not notice the peer exit.');
    }

    public function testCloseReleasesUnauthenticatedConnectionsAndTheListener(): void
    {
        $this->connect();
        $this->channel->accept();
        $address = $this->channel->address;
        $this->channel->close();
        self::assertFalse(@stream_socket_client('tcp://' . $address, $code, $error, 0.1));
    }

    /** @return resource */
    private function connect()
    {
        $connection = stream_socket_client('tcp://' . $this->channel->address, $code, $error, 1);
        self::assertIsResource($connection);
        stream_set_blocking($connection, false);
        $this->connections[] = $connection;
        return $connection;
    }

    private function authenticate(): void
    {
        $until = microtime(true) + 2;
        do {
            if ($this->channel->accept()) {
                return;
            }
            usleep(1000);
        } while (microtime(true) < $until);
        self::fail('Control channel authentication timed out.');
    }

    /** @param resource $connection */
    private function receive($connection, int $lines = 1): string
    {
        $result = '';
        $until = microtime(true) + 4;
        do {
            $this->channel->poll();
            $chunk = fread($connection, 65536);
            self::assertIsString($chunk);
            $result .= $chunk;
            if (substr_count($result, "\n") === $lines) {
                return $result;
            }
            if ($chunk === '') {
                usleep(1000);
            }
        } while (microtime(true) < $until);
        self::fail('Control channel update timed out.');
    }
}
