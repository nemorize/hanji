<?php

declare(strict_types=1);

namespace Hanji\Plugin;

use Hanji\Output\Extension;
use Hanji\Support\Maps;

final readonly class BuildOutput
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $data
     */
    public function __construct(public string $content, public string $extension = 'html', public array $context = [], public array $data = [])
    {
        Extension::validate($extension);
        Variables::validate($context);
        Maps::validate($data, 'Build output');
    }
}
