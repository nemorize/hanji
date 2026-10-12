<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\BuildException;
use Hanji\Runtime\Context;
use Hanji\Runtime\ContextStack;
use Hanji\Support\Scripts;

/** Illuminate's default engine trims output; generated text formats must retain their bytes. */
final class CompilerEngine extends \Illuminate\View\Engines\CompilerEngine
{
    /**
     * @param string $path
     * @param array<string, mixed> $data
     */
    protected function evaluatePath($path, $data): string
    {
        $parent = ContextStack::current();
        $origin = $this->compiler instanceof BladeCompiler ? $this->compiler->origin($path) : $path;
        $context = new Context($parent->phase, $origin, $parent->data, $parent->params, $parent->extension, $parent->children, $parent->origins);
        try {
            return ContextStack::run($context, static fn(): string => Scripts::require($path, $data)[1]);
        } catch (\Throwable $error) {
            throw new BuildException("{$origin}: {$error->getMessage()}", 0, $error, source: $origin);
        }
    }
}
