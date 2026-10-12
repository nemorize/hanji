<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\Diagnostic;

final class LiveState
{
    public readonly string $session;
    private int $revision = 0;
    private string $status = 'building';
    private ?Diagnostic $error = null;
    private bool $published;
    /** @var list<array{revision: int, type: string, paths: list<string>}> */
    private array $changes = [];

    public function __construct(public Project $project, private readonly string $path, private readonly string $host)
    {
        $this->session = bin2hex(random_bytes(12));
        $this->published = $project->published();
        $this->save();
    }

    public function building(): void
    {
        $this->status = 'building';
        $this->save();
    }

    public function failed(Diagnostic $error): void
    {
        $this->status = 'error';
        $this->error = $error;
        $this->save();
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     */
    public function ready(Project $project, array $before, array $after): void
    {
        $paths = Snapshot::changes($before, $after);
        $type = $paths === [] ? 'none' : 'css';
        foreach ($paths as $path) {
            if (!isset($before[$path], $after[$path]) || !str_ends_with(strtolower($path), '.css')) {
                $type = 'reload';
            }
        }
        if ($this->revision === 0 || $project->output !== $this->project->output || $project->basePath !== $this->project->basePath) {
            $type = 'reload';
        }
        $this->project = $project;
        $this->published = true;
        $this->status = 'ready';
        $this->error = null;
        $this->changes[] = ['revision' => ++$this->revision, 'type' => $type, 'paths' => array_map(static fn(string $path): string => $project->url(substr($path, strlen($project->output) + 1)), $paths)];
        $this->changes = array_slice($this->changes, -32);
        $this->save();
    }

    private function save(): void
    {
        $root = rtrim($this->project->root, '/') . '/';
        $error = $this->error === null ? null : ['message' => str_replace($root, '', $this->error->message), 'source' => $this->error->source === null ? null : str_replace($root, '', $this->error->source), 'url' => $this->error->url, 'hints' => $this->error->hints];
        Protocol::write($this->path, ['project' => $this->project, 'published' => $this->published, 'host' => $this->host, 'session' => $this->session, 'revision' => $this->revision, 'status' => $this->status, 'error' => $error, 'changes' => $this->changes, 'basePath' => $this->project->url('/')]);
    }
}
