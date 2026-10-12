<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\Support\Paths;

final class Snapshot
{
    /**
     * @param list<string> $dependencies
     * @param list<string> $excluded
     * @return array<string, string>
     */
    public static function sources(Project $project, string $config, array $dependencies = [], array $excluded = []): array
    {
        $files = self::tree($project->root, [...$excluded, $project->output, $project->cache, $project->root . '/.hanji'], true);
        $files = [...$files, ...self::tree($project->input)];
        foreach ([$config, ...$dependencies] as $path) {
            if ($path !== $config && (self::excluded($path, [...$excluded, $project->output, $project->cache, $project->root . '/.hanji']) || preg_match('~/(?:vendor|node_modules)/~', $path))) {
                continue;
            }
            $files[$path] = self::hash($path);
        }
        ksort($files);
        return $files;
    }

    /**
     * @param list<string> $excluded
     * @return array<string, string>
     */
    public static function tree(string $root, array $excluded = [], bool $project = false): array
    {
        $files = [];
        self::directory($root, $files, $excluded, $project);
        ksort($files);
        return $files;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
            if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                $changed[] = $path;
            }
        }
        sort($changed);
        return $changed;
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $excluded
     */
    private static function directory(string $root, array &$files, array $excluded, bool $project): void
    {
        if (!is_dir($root)) {
            return;
        }
        try {
            $entries = new \DirectoryIterator($root);
        } catch (\UnexpectedValueException) {
            $files[$root] = 'unreadable';
            return;
        }
        foreach ($entries as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = str_replace('\\', '/', $entry->getPathname());
            if (self::excluded($path, $excluded) || ($project && $entry->isDir() && (in_array($entry->getFilename(), ['.git', '.attic', 'vendor', 'node_modules'], true) || str_starts_with($entry->getFilename(), '.hanji-build-')))) {
                continue;
            }
            if ($entry->isLink()) {
                $files[$path] = 'link:' . (readlink($path) ?: '');
            } elseif ($entry->isDir()) {
                self::directory($path, $files, $excluded, $project);
            } elseif ($entry->isFile()) {
                $files[$path] = self::hash($path);
            }
        }
    }

    /** @param list<string> $excluded */
    private static function excluded(string $path, array $excluded): bool
    {
        foreach ($excluded as $root) {
            if (Paths::within($path, $root)) {
                return true;
            }
        }
        return false;
    }

    private static function hash(string $path): string
    {
        if (is_link($path)) {
            return 'link:' . (readlink($path) ?: '');
        }
        return is_file($path) ? (@hash_file('sha256', $path) ?: 'unreadable') : 'missing';
    }
}
