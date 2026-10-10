<?php

declare(strict_types=1);

namespace Hanji\Source;

final readonly class Declaration
{
    public function __construct(public string $body, public string $php) {}
}
