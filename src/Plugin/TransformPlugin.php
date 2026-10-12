<?php

declare(strict_types=1);

namespace Hanji\Plugin;

/** Postprocess the fully assembled output once per file, after layouts. */
interface TransformPlugin extends Plugin
{
    public function supports(string $path, BuildContext $context): bool;

    public function transform(string $path, BuildContext $context, string $content): string;
}
