<?php

declare(strict_types=1);

namespace Hanji\Plugin;

use Hanji\Output\Extension;
use Hanji\Support\Maps;

final readonly class BuildPreparation
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $data
     */
    public function __construct(public string $extension = 'html', public ?string $body = null, public array $context = [], public array $data = [], public ?string $sourceExtension = null)
    {
        Extension::validate($extension);
        Variables::validate($context);
        Maps::validate($data, 'Build preparation');
    }
}
