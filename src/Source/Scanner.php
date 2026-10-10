<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\BuildException;
use Symfony\Component\Finder\Finder;

final class Scanner
{
    /** @return array<string, string> */
    public function scan(string $input): array
    {
        if (!is_dir($input)) {
            throw new BuildException("Input directory does not exist: {$input}");
        }
        $files = [];
        $finder = (new Finder())->in($input)->ignoreDotFiles(false)->ignoreVCS(false)->sortByName();
        foreach ($finder as $file) {
            if ($file->isLink()) {
                throw new BuildException("Input symlinks are not supported: {$file->getPathname()}");
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if ($file->getFilename() === '@params.php' && !preg_match('/^\[[A-Za-z_][A-Za-z0-9_]*\]$/D', basename($file->getPath()))) {
                throw new BuildException("{$relative}: @params.php requires a dynamic directory.");
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);
        return $files;
    }

    public static function kind(string $path): string
    {
        if (str_starts_with(basename($path), '@')) {
            return 'special';
        }
        return match (true) {
            str_ends_with($path, '.blade.php') => 'blade',
            str_ends_with($path, '.php') => 'php',
            str_ends_with($path, '.md') => 'markdown',
            str_ends_with($path, '.scss') => 'scss',
            default => 'static',
        };
    }
}
