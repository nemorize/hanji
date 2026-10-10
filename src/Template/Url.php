<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\Settings;

final readonly class Url
{
    public function __construct(private Settings $settings) {}

    public function url(string $path): string
    {
        if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i', $path)) {
            return $path;
        }
        $length = strcspn($path, '?#');
        return $this->settings->url(substr($path, 0, $length)) . substr($path, $length);
    }
}
