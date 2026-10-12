<?php

declare(strict_types=1);

namespace Hanji\Runtime;

use Hanji\Output\Origins;
use Illuminate\Support\HtmlString;

final class Context
{
    public bool $metaDeclared = false;
    public bool $extensionDeclared = false;
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $params
     */
    public function __construct(public string $phase, public string $source, public array $data = [], public array $params = [], public string $extension = 'html', public ?HtmlString $children = null, public ?Origins $origins = null) {}
}
