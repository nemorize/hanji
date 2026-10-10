<?php

declare(strict_types=1);

namespace Hanji\Output;

use Hanji\BuildException;
use Hanji\Support\Paths;

final class Registry
{
    /** @var array<string, string> */
    private array $files = [];
    /** @var array<string, string> */
    private array $directories = [];

    public function reserve(string $path, string $source): void
    {
        Paths::relative($path);
        $key = Paths::key($path);
        if (isset($this->files[$key]) || isset($this->directories[$key])) {
            throw new BuildException("Output collision at {$path}: {$source} and " . ($this->files[$key] ?? $this->directories[$key]));
        }
        $parent = dirname($key);
        while ($parent !== '.') {
            if (isset($this->files[$parent])) {
                throw new BuildException("File/directory collision at {$path}: {$source} and {$this->files[$parent]}");
            }
            $this->directories[$parent] = $source;
            $parent = dirname($parent);
        }
        $this->files[$key] = $source;
    }
}
