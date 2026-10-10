<?php

declare(strict_types=1);

namespace Hanji\Support;

use Hanji\BuildException;
use Symfony\Component\Filesystem\Path;

final class Paths
{
    public static function absolute(string $path, string $root): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new BuildException('Paths must be non-empty and contain no NUL bytes.');
        }
        $path = Path::makeAbsolute($path, $root);
        $ancestor = $path;
        $tail = [];
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $tail[] = basename($ancestor);
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        $resolved = realpath($ancestor);
        return Path::canonicalize(($resolved === false ? $ancestor : $resolved) . ($tail === [] ? '' : '/' . implode('/', array_reverse($tail))));
    }

    public static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    public static function relative(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/[\\\\\x00-\x1f\x7f]|^[A-Za-z]:/', $path)) {
            throw new BuildException("Invalid relative output path: {$path}");
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[:*?"<>|]|[. ]$/', $segment) || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment)) {
                throw new BuildException("Invalid path segment in {$path}");
            }
        }
        return $path;
    }

    public static function segment(string|int $value): string
    {
        $value = (string) $value;
        if ($value === '' || trim($value) !== $value || str_starts_with($value, '.') || preg_match('/[\\\\\/:*?"<>|%#\x00-\x1f\x7f]/u', $value) || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $value) || str_ends_with($value, '.')) {
            throw new BuildException("Invalid dynamic path value: {$value}");
        }
        return $value;
    }

    public static function key(string $path): string
    {
        $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
        return mb_strtolower(is_string($normalized) ? $normalized : $path, 'UTF-8');
    }

    public static function encode(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
