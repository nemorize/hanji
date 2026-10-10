<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\BuildException;

final class ViewFinder extends \Illuminate\View\FileViewFinder
{
    /**
     * @param string $name
     * @param string|list<string> $paths
     */
    protected function findInPaths($name, $paths): string
    {
        foreach ((array) $paths as $path) {
            $matches = [];
            foreach ($this->getPossibleViewFiles($name) as $file) {
                if (is_file($path . '/' . $file)) {
                    $matches[] = $path . '/' . $file;
                }
            }
            if (count($matches) > 1) {
                throw new BuildException('Ambiguous view ' . $name . ': ' . implode(', ', $matches));
            }
            if ($matches !== []) {
                return $matches[0];
            }
        }
        throw new \InvalidArgumentException("View [{$name}] not found.");
    }

    /**
     * @param string $name
     * @return list<string>
     */
    protected function getPossibleViewFiles($name): array
    {
        $base = str_replace('.', '/', $name);
        $special = (dirname($base) === '.' ? '' : dirname($base) . '/') . '@' . basename($base);
        return [$base . '.blade.php', $special . '.blade.php'];
    }
}
