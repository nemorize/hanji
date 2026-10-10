<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\BuildException;
use Hanji\Support\Maps;
use Hanji\Support\Paths;
use Hanji\Support\Scripts;
use Symfony\Component\Yaml\Yaml;

final class DataResolver
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function read(string $path): mixed
    {
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new BuildException("Cannot read {$path}");
        }
        $value = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'json' => json_decode($text, true, 512, JSON_THROW_ON_ERROR),
            'yaml', 'yml' => Yaml::parse($text),
            'php' => $this->php($path),
            default => throw new BuildException("Unsupported data format: {$path}"),
        };
        return $this->cache[$path] = $value;
    }

    private function php(string $path): mixed
    {
        [$value, $output] = Scripts::require($path);
        if ($output !== '') {
            throw new BuildException("{$path}: data files must not emit output.");
        }
        return $value;
    }

    /**
     * @param array<string, string> $files
     * @return array<string, mixed>
     */
    public function global(string $directory, array $files): array
    {
        $result = [];
        $keys = [];
        foreach ($files as $path) {
            if (!Paths::within($path, $directory) || !preg_match('/^@(.+)\.(json|ya?ml|php)$/D', basename($path), $match) || str_starts_with(basename($path), '@data.') || basename($path) === '@params.php') {
                continue;
            }
            $relative = substr($path, strlen($directory) + 1);
            $segments = explode('/', dirname($relative) === '.' ? $match[1] : dirname($relative) . '/' . $match[1]);
            $key = implode('.', $segments);
            if (isset($keys[$key])) {
                throw new BuildException("Duplicate global data name: {$key}");
            }
            $keys[$key] = true;
            $branch = $this->read($path);
            foreach (array_reverse($segments) as $segment) {
                $branch = [$segment => $branch];
            }
            $result = Maps::merge($result, Maps::validate($branch, $path));
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function directory(string $directory): array
    {
        return $this->sidecar($directory . '/@data');
    }

    /** @return array<string, mixed> */
    public function sidecar(string $base): array
    {
        $paths = array_values(array_filter(array_map(static fn(string $extension): string => $base . '.' . $extension, ['php', 'json', 'yaml', 'yml']), is_file(...)));
        if (count($paths) > 1) {
            throw new BuildException("Duplicate data formats: {$base}");
        }
        return $paths === [] ? [] : Maps::validate($this->read($paths[0]), $paths[0]);
    }
}
