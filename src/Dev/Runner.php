<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\BuildProgress;
use Hanji\Console\Reporter;
use Hanji\Diagnostic;
use Hanji\Support\Paths;
use Symfony\Component\Filesystem\Filesystem;

final class Runner
{
    private readonly string $directory;
    private readonly ?string $config;
    private readonly string $configPath;
    private readonly string $session;
    private ?Worker $worker = null;
    private ?HttpServer $http = null;
    private ?LiveState $state = null;
    private readonly Watcher $watcher;
    /** @var array<string, string> */
    private array $output = [];
    /** @var list<string> */
    private array $dependencies = [];
    /** @var list<string> */
    private array $excluded = [];
    /** @var array<string, true> */
    private array $pending = [];
    private float $changedAt = 0;
    private bool $stopped = false;

    public function __construct(string $directory, ?string $config, private readonly Reporter $reporter, private readonly string $host = '127.0.0.1', private readonly int $port = 3000)
    {
        HttpServer::validate($host, $port);
        $this->watcher = new Watcher();
        $this->directory = Paths::absolute($directory, getcwd() ?: '.');
        $this->config = $config === null ? null : Paths::absolute($config, $this->directory);
        $this->configPath = $this->config ?? $this->directory . '/hanji.php';
        $this->session = sys_get_temp_dir() . '/hanji-dev-' . bin2hex(random_bytes(12));
        (new Filesystem())->mkdir($this->session . '/public', 0700);
    }

    public function start(): void
    {
        $worker = new Worker($this->directory, $this->config, $this->session);
        $this->worker = $worker;
        do {
            $outcome = $worker->poll($this->progress(...));
            if ($this->stopped) {
                return;
            }
            if ($outcome === null) {
                $this->observe();
                usleep(10000);
            }
        } while ($outcome === null);
        $this->worker = null;
        $project = $outcome->project;
        if ($project === null) {
            throw new Failure($outcome->error ?? new Diagnostic('Cannot load the preview project.'), $outcome->configurationError);
        }
        $this->state = new LiveState($project, $this->session . '/state.json', $this->host);
        $this->accept($outcome);
        $this->http = new HttpServer($this->host, $this->port, $this->session);
        $this->reporter->serving($this->http->url($project), $this->watcher->name());
    }

    public function tick(): void
    {
        $state = $this->state;
        if ($this->http === null || $state === null) {
            throw new BuildException('The preview server has not started.');
        }
        $this->reporter->requests($this->http->poll());
        $this->observe();
        if ($this->worker !== null) {
            $outcome = $this->worker->poll($this->progress(...));
            if ($outcome !== null) {
                $this->worker = null;
                $this->accept($outcome);
            }
        }
        if ($this->worker === null && $this->pending !== [] && microtime(true) - $this->changedAt >= 0.12) {
            $this->reporter->changed(array_keys($this->pending));
            $this->pending = [];
            $state->building();
            $this->publish();
            $this->worker = new Worker($this->directory, $this->config, $this->session);
        }
    }

    public function run(): void
    {
        $signals = [];
        $async = false;
        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            $async = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                $signals[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (): void {
                    $this->stopped = true;
                });
            }
        }
        register_shutdown_function($this->close(...));
        try {
            $this->start();
            while (!$this->stopped) {
                $this->tick();
                usleep(20000);
            }
        } finally {
            $this->close();
            foreach ($signals as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals($async);
            }
        }
    }

    public function url(): string
    {
        if ($this->http === null || $this->state === null) {
            throw new BuildException('The preview server has not started.');
        }
        return $this->http->url($this->state->project);
    }

    public function close(): void
    {
        $this->stopped = true;
        $this->worker?->close();
        $this->http?->close();
        $this->watcher->close();
        $this->worker = null;
        $this->http = null;
        (new Filesystem())->remove($this->session);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function progress(Project|BuildProgress $progress): void
    {
        if ($progress instanceof Project) {
            $this->configure($progress);
            $this->reporter->project($progress);
        } else {
            $this->reporter->progress($progress);
        }
    }

    private function accept(Outcome $outcome): void
    {
        $this->dependencies = $outcome->result === null ? array_values(array_unique([...$this->dependencies, ...$outcome->dependencies])) : $outcome->dependencies;
        if ($outcome->project !== null) {
            $this->configure($outcome->project);
        }
        if ($outcome->result !== null && $outcome->project !== null && $this->state !== null) {
            $next = Snapshot::tree($outcome->project->output);
            $this->state->ready($outcome->project, $this->output, $next);
            $this->output = $next;
            $this->reporter->built($outcome->result);
        } elseif ($outcome->error !== null) {
            $this->state?->failed($outcome->error);
            $this->reporter->diagnostic($outcome->error);
        }
        $this->publish();
    }

    private function publish(): void
    {
        if ($this->http !== null) {
            $this->http->publish(Protocol::read($this->session . '/state.json'));
        }
    }

    private function configure(Project $project): void
    {
        $this->excluded = array_values(array_unique([...$this->excluded, $project->output, $project->cache, $this->session]));
        $this->watcher->configure($project, $this->configPath, $this->dependencies, $this->excluded);
    }

    private function observe(): void
    {
        $changes = $this->watcher->changes();
        if ($changes !== []) {
            foreach ($changes as $path) {
                $this->pending[$path] = true;
            }
            $this->changedAt = microtime(true);
        }
    }
}
