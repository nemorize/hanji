<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\Page;

final readonly class PreparedPage
{
    public function __construct(public Instance $instance, public Page $page, public string $body) {}
}
