<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

use Hanji\BuildException;

final class WindowsDirectory
{
    public const TYPES = <<<'C'
        typedef uint32_t DWORD;
        typedef uint16_t WCHAR;
        typedef int BOOL;
        typedef void * HANDLE;
        typedef struct {
            uintptr_t Internal;
            uintptr_t InternalHigh;
            DWORD Offset;
            DWORD OffsetHigh;
            HANDLE hEvent;
        } OVERLAPPED;
        C;

    private ?\FFI\CData $directory = null;
    private ?\FFI\CData $event = null;
    private readonly \FFI\CData $overlapped;
    private readonly \FFI\CData $buffer;
    private readonly \FFI\CData $bytes;
    private bool $pending = false;
    private bool $active = true;

    public function __construct(private readonly Native $api, public readonly string $root, private readonly bool $recursive)
    {
        $this->overlapped = $api->allocate('OVERLAPPED');
        $this->buffer = $api->allocate('DWORD[16384]');
        $this->bytes = $api->allocate('DWORD[1]');
        try {
            $path = mb_convert_encoding(WindowsNotifications::nativePath($root) . "\0", 'UTF-16LE', 'UTF-8');
            $wide = $api->allocate('WCHAR[' . intdiv(strlen($path), 2) . ']');
            \FFI::memcpy($wide, $path, strlen($path));
            $directory = $api->pointer('CreateFileW', $wide, 1, 7, null, 3, 0x42000000, null);
            if ($api->member($api->cast('intptr_t', $directory), 'cdata') === -1) {
                throw new BuildException('Cannot watch Windows directory: ' . $root . ' (error ' . $api->integer('GetLastError') . ').');
            }
            $this->directory = $directory;
            $this->event = $api->pointer('CreateEventW', null, 1, 0, null);
            $this->arm();
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @return list<string> */
    public function events(): array
    {
        if (!$this->active || $this->directory === null || $this->event === null) {
            return [];
        }
        $wait = $this->api->integer('WaitForSingleObject', $this->event, 0);
        if ($wait === 258) {
            return [];
        }
        if ($wait !== 0) {
            throw new BuildException('Cannot wait for Windows file notifications.');
        }
        $result = $this->api->integer('GetOverlappedResult', $this->directory, \FFI::addr($this->overlapped), $this->bytes, 0);
        if ($result === 0) {
            $error = $this->api->integer('GetLastError');
            if ($error === 996) {
                return [];
            }
            $this->pending = false;
            if ($error !== 1022) {
                $this->active = false;
                return [$this->root];
            }
            $this->arm();
            return [$this->root];
        }
        $this->pending = false;
        $length = $this->bytes[0];
        if (!is_int($length) || $length < 0 || $length > 65536) {
            throw new BuildException('Invalid Windows notification buffer length.');
        }
        $data = $length === 0 ? '' : \FFI::string($this->api->cast('char *', $this->buffer), $length);
        $this->arm();
        return $length === 0 ? [$this->root] : WindowsNotifications::paths($this->root, $data);
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function close(): void
    {
        if ($this->directory !== null) {
            if ($this->pending) {
                $this->api->integer('CancelIoEx', $this->directory, \FFI::addr($this->overlapped));
                $this->api->integer('GetOverlappedResult', $this->directory, \FFI::addr($this->overlapped), $this->bytes, 1);
                $this->pending = false;
            }
            $this->api->integer('CloseHandle', $this->directory);
            $this->directory = null;
        }
        if ($this->event !== null) {
            $this->api->integer('CloseHandle', $this->event);
            $this->event = null;
        }
        $this->active = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function arm(): void
    {
        if ($this->directory === null || $this->event === null) {
            throw new BuildException('Windows directory watcher is closed.');
        }
        \FFI::memset($this->overlapped, 0, \FFI::sizeof($this->overlapped));
        $this->api->set($this->overlapped, 'hEvent', $this->event);
        $this->api->integer('ResetEvent', $this->event);
        if ($this->api->integer('ReadDirectoryChangesW', $this->directory, $this->buffer, 65536, $this->recursive ? 1 : 0, 0x15f, null, \FFI::addr($this->overlapped), null) === 0) {
            throw new BuildException('Cannot start Windows directory watching: ' . $this->root . ' (error ' . $this->api->integer('GetLastError') . ').');
        }
        $this->pending = true;
    }
}
