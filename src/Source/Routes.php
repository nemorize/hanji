<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\BuildException;
use Hanji\Support\Paths;

final class Routes
{
    /** @return array{string, string} Output path and unencoded public path. */
    public static function resolve(string $route, string $extension, ?string $permalink): array
    {
        $stem = preg_replace('/(?:\.blade)?\.(?:php|md)$/i', '', $route) ?? $route;
        if ($permalink !== null) {
            if (!str_starts_with($permalink, '/') || str_starts_with($permalink, '//') || preg_match('/[\\\\:%?#\x00-\x1f]/', $permalink)) {
                throw new BuildException("Invalid permalink: {$permalink}");
            }
            $stem = ltrim($permalink, '/');
        }
        $directory = str_ends_with($stem, '/') || $stem === '';
        $stem = rtrim($stem, '/');
        if ($stem !== '') {
            Paths::relative($stem);
        }
        if ($extension === 'html') {
            if (!$directory && str_ends_with($stem, '.html')) {
                return [$stem, '/' . $stem];
            }
            if ($permalink === null && basename($stem) === 'index') {
                $stem = dirname($stem) === '.' ? '' : dirname($stem);
            }
            $path = ($stem === '' ? '' : $stem . '/') . 'index.html';
            return [$path, '/' . ($stem === '' ? '' : $stem . '/')];
        }
        if ($directory) {
            throw new BuildException('Non-HTML output requires a file permalink.');
        }
        $suffix = pathinfo($stem, PATHINFO_EXTENSION);
        if ($permalink !== null && $suffix !== '' && $suffix !== $extension) {
            throw new BuildException("Permalink extension must be .{$extension}.");
        }
        $path = $suffix === $extension ? $stem : $stem . '.' . $extension;
        return [$path, '/' . $path];
    }
}
