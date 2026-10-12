<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\BuildException;
use Hanji\Runtime\Context;
use Hanji\Runtime\ContextStack;
use Hanji\Settings;
use Hanji\Support\Maps;
use Hanji\Support\Paths;
use Hanji\Support\Scripts;
use Hanji\Plugin\Registry;
use Hanji\Plugin\AssetBuildPlugin;

final class Expander
{
    public function __construct(private readonly Settings $settings, private readonly DataResolver $data, private readonly Registry $plugins) {}

    /**
     * @param array<string, mixed> $global
     * @return list<Instance>
     */
    public function expand(array $global): array
    {
        return $this->walk($this->settings->input, '', [], $global);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $inherited
     * @return list<Instance>
     */
    private function walk(string $directory, string $route, array $params, array $inherited): array
    {
        $data = Maps::merge($inherited, $this->data->directory($directory));
        $name = basename($directory);
        if ($directory !== $this->settings->input && preg_match('/^\[([A-Za-z_][A-Za-z0-9_]*)\]$/D', $name, $match)) {
            $key = $match[1];
            if (array_key_exists($key, $params)) {
                throw new BuildException("{$directory}: duplicate dynamic parameter {$key}.");
            }
            $provider = $directory . '/@params.php';
            if (!is_file($provider)) {
                throw new BuildException("Missing parameter provider: {$provider}");
            }
            $context = new Context('params', $provider, $data, $params);
            [$rows, $output] = ContextStack::run($context, fn(): array => Scripts::require($provider, ['site' => $this->settings->site]));
            if ($output !== '' || !is_array($rows) || !array_is_list($rows)) {
                throw new BuildException("{$provider}: expected an array list without output.");
            }
            $result = [];
            $seen = [];
            $maps = isset($rows[0]) && is_array($rows[0]);
            foreach ($rows as $row) {
                $itemData = [];
                if ($maps) {
                    $row = Maps::validate($row, $provider);
                    if (array_diff(array_keys($row), ['value', 'data']) !== [] || !array_key_exists('value', $row)) {
                        throw new BuildException("{$provider}: parameter rows require value and optional data.");
                    }
                    $itemData = Maps::validate($row['data'] ?? [], $provider);
                    $row = $row['value'];
                }
                if (!is_string($row) && !is_int($row)) {
                    throw new BuildException("{$provider}: parameter values must be strings or integers, with a consistent row format.");
                }
                $value = Paths::segment($row);
                $normalized = Paths::key($value);
                if (isset($seen[$normalized])) {
                    throw new BuildException("{$provider}: duplicate parameter value {$value}.");
                }
                $seen[$normalized] = true;
                $parentRoute = dirname($route);
                $expanded = ($parentRoute === '.' ? '' : $parentRoute . '/') . $value;
                array_push($result, ...$this->contents($directory, $expanded, [...$params, $key => $value], Maps::merge($data, $itemData)));
            }
            return $result;
        }
        if (str_contains($name, '[') || str_contains($name, ']')) {
            throw new BuildException("Invalid dynamic directory name: {$directory}");
        }
        return $this->contents($directory, $route, $params, $data);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $data
     * @return list<Instance>
     */
    private function contents(string $directory, string $route, array $params, array $data): array
    {
        $result = [];
        $entries = scandir($directory);
        if ($entries === false) {
            throw new BuildException("Cannot scan {$directory}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $directory . '/' . $entry;
            $routePath = ($route === '' ? '' : $route . '/') . $entry;
            if (is_dir($source)) {
                array_push($result, ...$this->walk($source, $routePath, $params, $data));
            } elseif (($plugin = $this->plugins->builder($source)) !== null && !$plugin instanceof AssetBuildPlugin) {
                $result[] = new Instance(substr($source, strlen($this->settings->input) + 1), $routePath, $source, $plugin, $data, $params);
            }
        }
        return $result;
    }
}
