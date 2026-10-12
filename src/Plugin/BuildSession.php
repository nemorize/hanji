<?php

declare(strict_types=1);

namespace Hanji\Plugin;

use Hanji\Settings;
use Hanji\Output\Origins;
use Hanji\Source\DataResolver;
use Hanji\Template\BladeEngine;

final class BuildSession
{
    /** @var array<string, string> */
    public array $bodies = [];
    private ?BladeEngine $blade = null;
    public readonly Origins $origins;

    /** @param array<string, string> $sources */
    public function __construct(public readonly Settings $settings, public readonly array $sources, public readonly DataResolver $data)
    {
        $this->origins = new Origins();
    }

    public function blade(): BladeEngine
    {
        return $this->blade ??= new BladeEngine($this->settings, $this->sources, $this->bodies);
    }

    public function close(): void
    {
        $this->blade?->close();
        $this->blade = null;
    }
}
