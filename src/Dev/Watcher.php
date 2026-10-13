<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\Dev\Watch\Backend;
use Hanji\Dev\Watch\FSEvents;
use Hanji\Dev\Watch\Inotify;
use Hanji\Dev\Watch\Polling;
use Hanji\Dev\Watch\Scope;
use Hanji\Dev\Watch\Windows;

final class Watcher
{
    private ?Backend $backend = null;
    private ?Scope $scope = null;
    /** @var array<string, string> */
    private array $snapshot = [];
    /** @var array<string, true> */
    private array $pending = [];

    /**
     * @param list<string> $dependencies
     * @param list<string> $excluded
     */
    public function configure(Project $project, string $config, array $dependencies, array $excluded): void
    {
        $scope = new Scope($project, $config, $dependencies, $excluded);
        if ($this->backend === null) {
            $this->backend = $this->create($scope);
        } else {
            $this->backend->configure($scope);
        }
        $next = Snapshot::sources($project, $config, $dependencies, $excluded);
        if ($this->scope !== null) {
            $before = array_filter($this->snapshot, $scope->includes(...), ARRAY_FILTER_USE_KEY);
            foreach (Snapshot::changes($before, $next) as $path) {
                if ($this->scope->includes($path)) {
                    $this->pending[$path] = true;
                }
            }
        }
        $this->scope = $scope;
        $this->snapshot = $next;
    }

    /** @return list<string> */
    public function changes(): array
    {
        $scope = $this->scope;
        $backend = $this->backend;
        if ($scope === null || $backend === null) {
            return [];
        }
        $events = array_filter($backend->events(), $scope->includes(...));
        if ($events !== []) {
            $next = Snapshot::sources($scope->project, $scope->config, $scope->dependencies, $scope->excluded);
            foreach (Snapshot::changes($this->snapshot, $next) as $path) {
                $this->pending[$path] = true;
            }
            $this->snapshot = $next;
        }
        $changed = array_keys($this->pending);
        $this->pending = [];
        sort($changed);
        return $changed;
    }

    public function name(): string
    {
        return $this->backend?->name() ?? 'not started';
    }

    public function close(): void
    {
        $this->backend?->close();
        $this->backend = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function create(Scope $scope): Backend
    {
        try {
            if (class_exists(\FFI::class)) {
                if (PHP_OS_FAMILY === 'Darwin') {
                    return new FSEvents($scope);
                }
                if (PHP_OS_FAMILY === 'Linux') {
                    return new Inotify($scope);
                }
                if (PHP_OS_FAMILY === 'Windows') {
                    return new Windows($scope);
                }
            }
        } catch (\Throwable) {
        }
        return new Polling($scope, 'native watcher unavailable');
    }
}
