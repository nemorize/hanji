<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

use Hanji\Dev\Project;
use Hanji\Support\Paths;

final readonly class Scope
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $excluded
     */
    public function __construct(public Project $project, public string $config, public array $dependencies, public array $excluded) {}

    public function includes(string $path): bool
    {
        if ($path === $this->config) {
            return true;
        }
        foreach ([...$this->excluded, $this->project->output, $this->project->cache, $this->project->root . '/.hanji'] as $root) {
            if (Paths::within($path, $root)) {
                return false;
            }
        }
        if (Paths::within($path, $this->project->input) || Paths::within($this->project->input, $path)) {
            return true;
        }
        foreach ([$this->config, ...$this->dependencies] as $source) {
            if (!preg_match('~/(?:vendor|node_modules)/~', $source) && ($path === $source || Paths::within($source, $path))) {
                return true;
            }
        }
        if (Paths::within($path, $this->project->root)) {
            $relative = substr($path, strlen($this->project->root));
            return !preg_match('~/(?:\.git|\.attic|vendor|node_modules|\.hanji-build-[^/]*)(?:/|$)~', $relative);
        }
        return false;
    }

    /** @return list<string> */
    public function roots(): array
    {
        $roots = [];
        foreach ([$this->project->root, $this->project->input, $this->config, ...$this->dependencies] as $path) {
            if (!$this->includes($path)) {
                continue;
            }
            while (!is_dir($path)) {
                $parent = dirname($path);
                if ($parent === $path) {
                    break;
                }
                $path = $parent;
            }
            if (is_dir($path)) {
                $roots[] = $path;
            }
        }
        $roots = array_values(array_unique($roots));
        usort($roots, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));
        $result = [];
        foreach ($roots as $root) {
            foreach ($result as $parent) {
                if (Paths::within($root, $parent)) {
                    continue 2;
                }
            }
            $result[] = $root;
        }
        sort($result);
        return $result;
    }

    /** @return list<string> */
    public function directories(): array
    {
        $directories = [];
        foreach ($this->roots() as $root) {
            $this->directory($root, $directories);
        }
        return $directories;
    }

    /** @param list<string> $directories */
    private function directory(string $path, array &$directories): void
    {
        if (is_link($path) || !is_dir($path)) {
            return;
        }
        $directories[] = $path;
        foreach (new \DirectoryIterator($path) as $entry) {
            if (!$entry->isDot() && $entry->isDir() && !$entry->isLink() && $this->includes($entry->getPathname())) {
                $this->directory($entry->getPathname(), $directories);
            }
        }
    }
}
