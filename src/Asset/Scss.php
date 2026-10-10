<?php

declare(strict_types=1);

namespace Hanji\Asset;

use Hanji\BuildException;
use Hanji\Settings;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;

final class Scss
{
    /** @return array{string, ?string} */
    public static function compile(string $source, string $target, Settings $settings, string $mode): array
    {
        try {
            $compiler = new Compiler();
            $compiler->setImportPaths([dirname($source), ...$settings->scssLoadPaths]);
            $compiler->setOutputStyle($mode === 'prod' ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED);
            if ($mode === 'dev') {
                $compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
                $compiler->setSourceMapOptions(['sourceMapURL' => basename($target) . '.map', 'sourceMapFilename' => basename($target), 'sourceMapBasepath' => $settings->input, 'sourceMapRootpath' => '/']);
            }
            $result = $compiler->compileFile($source);
            return [$result->getCss(), $result->getSourceMap()];
        } catch (\Throwable $error) {
            $hint = str_contains($error->getMessage(), '@use') || str_contains($error->getMessage(), '@forward') ? ' PHP SCSS compilation currently supports @import, not Sass modules (@use/@forward).' : '';
            throw new BuildException("{$source}: {$error->getMessage()}{$hint}", 0, $error);
        }
    }
}
