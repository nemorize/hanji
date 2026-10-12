<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\Plugin\BuildPlugin;

final readonly class Instance
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $params
     */
    public function __construct(public string $inputPath, public string $routePath, public string $source, public BuildPlugin $plugin, public array $data, public array $params) {}
}
