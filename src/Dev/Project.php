<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\Settings;
use Hanji\Support\Maps;
use Hanji\Support\Paths;

final readonly class Project
{
    public function __construct(public string $root, public string $input, public string $output, public string $cache, public string $basePath) {}

    public static function from(Settings $settings): self
    {
        return new self($settings->root, $settings->input, $settings->output, $settings->cache, $settings->site['basePath']);
    }

    public static function decode(mixed $value): self
    {
        $data = Maps::validate($value, 'Preview project');
        foreach (['root', 'input', 'output', 'cache', 'basePath'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new BuildException("Invalid preview project {$key}.");
            }
        }
        return new self($data['root'], $data['input'], $data['output'], $data['cache'], $data['basePath']);
    }

    public function url(string $path): string
    {
        return Paths::encode(rtrim($this->basePath, '/') . '/' . ltrim($path, '/'));
    }

    public function published(): bool
    {
        $file = $this->root . '/.hanji/state/' . hash('sha256', $this->output) . '.json';
        $content = is_file($file) ? @file_get_contents($file) : false;
        $state = $content === false ? null : json_decode($content, true);
        return is_array($state) && ($state['root'] ?? null) === $this->root && ($state['output'] ?? null) === $this->output;
    }
}
