<?php

declare(strict_types=1);

namespace Hanji\Plugin;

interface PreparesBuild extends BuildPlugin
{
    public function prepare(string $path, BuildContext $context): BuildPreparation;
}
