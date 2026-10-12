<?php

declare(strict_types=1);

namespace Hanji\Plugin;

interface BuildPlugin extends Plugin
{
    public function supports(string $path): bool;

    public function build(string $path, BuildContext $context): BuildOutput;
}
