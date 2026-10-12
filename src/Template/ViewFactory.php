<?php

declare(strict_types=1);

namespace Hanji\Template;

final class ViewFactory extends \Illuminate\View\Factory
{
    /** @param array<string, mixed> $scope */
    public function scope(array $scope): void
    {
        $this->shared = ['__env' => $this, ...$scope];
    }
}
