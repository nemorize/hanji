<?php

declare(strict_types=1);

namespace Hanji\Plugin;

use Hanji\Source\Scanner;

final class Registry
{
    /** @var array<string, BuildPlugin|null> */
    private array $builders = [];
    /** @param list<Plugin> $plugins */
    public function __construct(private readonly array $plugins) {}

    public function builder(string $path): ?BuildPlugin
    {
        if (Scanner::special($path)) {
            return null;
        }
        if (array_key_exists($path, $this->builders)) {
            return $this->builders[$path];
        }
        foreach ($this->plugins as $plugin) {
            if ($plugin instanceof BuildPlugin && $plugin->supports($path)) {
                return $this->builders[$path] = $plugin;
            }
        }
        return $this->builders[$path] = null;
    }

    /** @return list<LayoutPlugin> */
    public function layouts(): array
    {
        return array_values(array_filter($this->plugins, static fn(Plugin $plugin): bool => $plugin instanceof LayoutPlugin));
    }

    /** @return list<TransformPlugin> */
    public function transforms(): array
    {
        return array_values(array_filter($this->plugins, static fn(Plugin $plugin): bool => $plugin instanceof TransformPlugin));
    }
}
