<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

use Hanji\BuildException;

final class FSEvents implements Backend
{
    private readonly Native $api;
    private readonly \FFI\CData $mode;
    private ?\FFI\CData $stream = null;
    private ?\Closure $callback = null;
    /** @var list<string> */
    private array $roots = [];
    /** @var array<string, true> */
    private array $pending = [];
    /** @var list<\FFI\CData> */
    private array $strings = [];

    public function __construct(Scope $scope)
    {
        $this->api = new Native(<<<'C'
            typedef const void * CFTypeRef;
            typedef void * FSEventStreamRef;
            typedef void (*FSEventStreamCallback)(const void *, void *, unsigned long, void *, const unsigned int *, const unsigned long long *);
            CFTypeRef CFStringCreateWithCString(const void *, const char *, unsigned int);
            void * CFArrayCreateMutable(const void *, long, const void *);
            void CFArrayAppendValue(void *, const void *);
            void CFRelease(const void *);
            void * CFRunLoopGetCurrent(void);
            int CFRunLoopRunInMode(const void *, double, unsigned char);
            FSEventStreamRef FSEventStreamCreate(const void *, FSEventStreamCallback, const void *, const void *, unsigned long long, double, unsigned int);
            void FSEventStreamScheduleWithRunLoop(FSEventStreamRef, void *, const void *);
            unsigned char FSEventStreamStart(FSEventStreamRef);
            void FSEventStreamStop(FSEventStreamRef);
            void FSEventStreamInvalidate(FSEventStreamRef);
            void FSEventStreamRelease(FSEventStreamRef);
            C, '/System/Library/Frameworks/CoreServices.framework/CoreServices');
        $this->mode = $this->api->pointer('CFStringCreateWithCString', null, 'kCFRunLoopDefaultMode', 0x08000100);
        try {
            $this->configure($scope);
        } catch (\Throwable $error) {
            $this->close();
            $this->api->call('CFRelease', $this->mode);
            throw $error;
        }
    }

    public function configure(Scope $scope): void
    {
        $roots = $scope->roots();
        if ($roots === $this->roots && $this->stream !== null) {
            return;
        }
        $this->stop();
        $paths = $this->api->pointer('CFArrayCreateMutable', null, 0, null);
        try {
            foreach ($roots as $root) {
                $string = $this->api->pointer('CFStringCreateWithCString', null, $root, 0x08000100);
                $this->strings[] = $string;
                $this->api->call('CFArrayAppendValue', $paths, $string);
            }
            $this->callback = function (\FFI\CData $stream, mixed $info, int $count, \FFI\CData $paths, \FFI\CData $flags, \FFI\CData $ids): void {
                for ($i = 0; $i < $count; ++$i) {
                    $this->pending[$this->api->stringAt($paths, $i)] = true;
                }
            };
            $this->stream = $this->api->pointer('FSEventStreamCreate', null, $this->callback, null, $paths, -1, 0.05, 0x02 | 0x04 | 0x10);
            $this->api->call('FSEventStreamScheduleWithRunLoop', $this->stream, $this->api->pointer('CFRunLoopGetCurrent'), $this->mode);
            if ($this->api->call('FSEventStreamStart', $this->stream) === 0) {
                throw new BuildException('Cannot start macOS FSEvents.');
            }
            $this->roots = $roots;
        } finally {
            $this->api->call('CFRelease', $paths);
        }
    }

    public function events(): array
    {
        $this->api->call('CFRunLoopRunInMode', $this->mode, 0.0, 1);
        $paths = array_keys($this->pending);
        $this->pending = [];
        return $paths;
    }

    public function name(): string
    {
        return 'FSEvents · native';
    }

    public function close(): void
    {
        $this->stop();
    }

    private function stop(): void
    {
        if ($this->stream !== null) {
            $this->api->call('FSEventStreamStop', $this->stream);
            $this->api->call('FSEventStreamInvalidate', $this->stream);
            $this->api->call('FSEventStreamRelease', $this->stream);
            $this->stream = null;
            $this->callback = null;
        }
        foreach ($this->strings as $string) {
            $this->api->call('CFRelease', $string);
        }
        $this->strings = [];
    }

    public function __destruct()
    {
        $this->close();
        $this->api->call('CFRelease', $this->mode);
    }
}
