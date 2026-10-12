<?php

declare(strict_types=1);

namespace Hanji;

final readonly class BuildProgress
{
    public function __construct(public string $stage, public int $completed = 0, public int $total = 0, public ?string $source = null, public ?string $output = null) {}
}
