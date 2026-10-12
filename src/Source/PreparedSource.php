<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\Page;
use Hanji\Plugin\BuildOutput;
use Hanji\Plugin\BuildPreparation;

final readonly class PreparedSource
{
    public function __construct(public Instance $instance, public Page $page, public BuildPreparation $preparation, public ?BuildOutput $output = null) {}
}
