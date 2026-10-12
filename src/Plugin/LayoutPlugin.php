<?php

declare(strict_types=1);

namespace Hanji\Plugin;

interface LayoutPlugin extends Plugin
{
    public function layout(string $path, BuildContext $context, BuildOutput $output): BuildOutput;
}
