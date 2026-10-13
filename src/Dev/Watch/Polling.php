<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

final class Polling implements Backend
{
    private float $next = 0;

    public function __construct(private Scope $scope, private readonly string $reason = '') {}

    public function configure(Scope $scope): void
    {
        $this->scope = $scope;
    }

    public function events(): array
    {
        if (microtime(true) < $this->next) {
            return [];
        }
        $this->next = microtime(true) + 0.5;
        return $this->scope->roots();
    }

    public function name(): string
    {
        return 'polling' . ($this->reason === '' ? '' : ' · ' . $this->reason);
    }

    public function close(): void {}
}
