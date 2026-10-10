<?php

declare(strict_types=1);

namespace Hanji;

final readonly class Page
{
    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $data
     */
    public function __construct(public string $inputPath, public string $routePath, public array $params, public string $url, public string $outputPath, public string $extension, public ?\DateTimeImmutable $date, public array $data) {}
}
