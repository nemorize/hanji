<?php

declare(strict_types=1);

namespace Hanji\Source;

final readonly class Instance
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $params
     */
    public function __construct(public string $inputPath, public string $routePath, public string $source, public string $kind, public array $data, public array $params) {}
}
