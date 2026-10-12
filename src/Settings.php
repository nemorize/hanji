<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Support\Paths;
use Hanji\Plugin\Plugin;

final readonly class Settings
{
    /** @param array{title: string, baseUrl: ?string, basePath: string, timezone: string} $site
     * @param array<string, string> $copy
     * @param list<Plugin> $plugins
     */
    public function __construct(public string $root, public string $input, public string $output, public string $cache, public string $includes, public string $dataDirectory, public array $site, public array $copy, public array $plugins, public bool $markdownHtml = false) {}

    public function url(string $path): string
    {
        return Paths::encode(rtrim($this->site['basePath'], '/') . '/' . ltrim($path, '/'));
    }
}
