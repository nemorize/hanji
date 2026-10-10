<?php

declare(strict_types=1);

namespace Hanji;

final readonly class BuildResult
{
    public function __construct(public int $pages, public int $assets, public string $output, public float $seconds) {}
}
