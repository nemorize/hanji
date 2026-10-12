<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\Support\Maps;
use Symfony\Component\Filesystem\Filesystem;

final class Protocol
{
    /** @return array<string, mixed> */
    public static function read(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new BuildException("Cannot read development state: {$path}");
        }
        return Maps::validate(json_decode($content, true, 512, JSON_THROW_ON_ERROR), $path);
    }

    public static function write(string $path, mixed $value): void
    {
        (new Filesystem())->dumpFile($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
