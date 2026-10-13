<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

use Hanji\BuildException;

final class Inotify implements Backend
{
    private readonly Native $api;
    private readonly \FFI\CData $buffer;
    private int $descriptor;
    private Scope $scope;
    /** @var array<int, string> */
    private array $directories = [];

    public function __construct(Scope $scope)
    {
        $this->api = new Native(<<<'C'
            int inotify_init1(int);
            int inotify_add_watch(int, const char *, unsigned int);
            int inotify_rm_watch(int, int);
            long read(int, void *, unsigned long);
            int close(int);
            C);
        $this->buffer = $this->api->buffer(65536);
        $this->descriptor = $this->api->integer('inotify_init1', 0x800 | 0x80000);
        if ($this->descriptor < 0) {
            throw new BuildException('Cannot initialize Linux inotify.');
        }
        try {
            $this->configure($scope);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function configure(Scope $scope): void
    {
        $this->scope = $scope;
        $directories = $scope->directories();
        foreach ($this->directories as $id => $path) {
            if (!in_array($path, $directories, true)) {
                $this->api->integer('inotify_rm_watch', $this->descriptor, $id);
                unset($this->directories[$id]);
            }
        }
        foreach ($directories as $directory) {
            if (!in_array($directory, $this->directories, true)) {
                $id = $this->api->integer('inotify_add_watch', $this->descriptor, $directory, 0x00000fce);
                if ($id < 0) {
                    throw new BuildException('Cannot watch directory with inotify: ' . $directory);
                }
                $this->directories[$id] = $directory;
            }
        }
    }

    public function events(): array
    {
        $paths = [];
        $refresh = false;
        while (($length = $this->api->integer('read', $this->descriptor, $this->buffer, 65536)) > 0) {
            $data = \FFI::string($this->buffer, $length);
            for ($offset = 0; $offset + 16 <= $length;) {
                $event = unpack('lwd/Lmask/Lcookie/Llength', substr($data, $offset, 16));
                if ($event === false || !is_int($event['wd']) || !is_int($event['mask']) || !is_int($event['length'])) {
                    throw new BuildException('Invalid inotify event.');
                }
                $root = $this->directories[$event['wd']] ?? null;
                if ($root !== null) {
                    $name = rtrim(substr($data, $offset + 16, $event['length']), "\0");
                    $paths[] = $root . ($name === '' ? '' : '/' . $name);
                } elseif (($event['mask'] & 0x4000) !== 0) {
                    $paths = [...$paths, ...$this->scope->roots()];
                }
                if (($event['mask'] & 0x8000) !== 0) {
                    unset($this->directories[$event['wd']]);
                }
                $refresh = $refresh || ($event['mask'] & (0x40000000 | 0x4000 | 0x8000)) !== 0;
                $offset += 16 + $event['length'];
            }
        }
        if ($refresh) {
            $this->configure($this->scope);
        }
        return array_values(array_unique($paths));
    }

    public function name(): string
    {
        return 'inotify · native';
    }

    public function close(): void
    {
        if ($this->descriptor >= 0) {
            $this->api->integer('close', $this->descriptor);
            $this->descriptor = -1;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
