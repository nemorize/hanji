<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

interface Backend
{
    public function configure(Scope $scope): void;
    /** @return list<string> */
    public function events(): array;
    public function name(): string;
    public function close(): void;
}
