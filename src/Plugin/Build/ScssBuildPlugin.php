<?php

declare(strict_types=1);

namespace Hanji\Plugin\Build;

use Hanji\BuildException;
use Hanji\Plugin\AssetBuildPlugin;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\BuildOutput;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;

final readonly class ScssBuildPlugin implements AssetBuildPlugin
{
    public function __construct(private bool $compressed = true) {}

    public function supports(string $path): bool
    {
        return str_ends_with($path, '.scss');
    }

    public function build(string $path, BuildContext $context): BuildOutput
    {
        if (!class_exists(Compiler::class)) {
            throw new BuildException('The SCSS plugin requires scssphp/scssphp. Install it with composer require scssphp/scssphp:^2.1.');
        }
        try {
            $compiler = new Compiler();
            $compiler->setImportPaths([dirname($path)]);
            $compiler->setOutputStyle($this->compressed ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED);
            return new BuildOutput($compiler->compileString($context->read($path), $path)->getCss(), 'css');
        } catch (\Throwable $error) {
            $hint = str_contains($error->getMessage(), '@use') || str_contains($error->getMessage(), '@forward') ? ' PHP SCSS compilation currently supports @import, not Sass modules (@use/@forward).' : '';
            throw new BuildException("{$path}: {$error->getMessage()}{$hint}", 0, $error);
        }
    }
}
