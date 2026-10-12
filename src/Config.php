<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Support\Paths;
use Hanji\Plugin\Plugin;
use Hanji\Plugin\Build\BladeBuildPlugin;
use Hanji\Plugin\Build\PhpBuildPlugin;
use Hanji\Plugin\Build\MarkdownBuildPlugin;
use Hanji\Plugin\TransformPlugin;
use Hanji\Plugin\Transform\RelativePathTransformPlugin;

final class Config
{
    private string $inputPath = 'site';
    private string $outputPath = '_site';
    private string $cachePath = '.hanji/cache';
    private string $includesPath = '_includes';
    private string $dataPath = '_data';
    private string $siteTitle = 'Hanji';
    private ?string $siteUrl = null;
    private string $siteBasePath = '/';
    private string $siteTimezone = 'UTC';
    private bool $allowMarkdownHtml = false;
    /** @var list<array{string, string}> */
    private array $copyMappings = [];
    /** @var list<Plugin> */
    private array $registeredPlugins;

    public function __construct()
    {
        $this->registeredPlugins = [new BladeBuildPlugin(), new PhpBuildPlugin(), new MarkdownBuildPlugin(), new RelativePathTransformPlugin()];
    }

    public function plugin(Plugin $plugin): self
    {
        $this->registeredPlugins = array_values(array_filter($this->registeredPlugins, static fn(Plugin $registered): bool => $registered::class !== $plugin::class));
        if ($plugin instanceof TransformPlugin) {
            $this->registeredPlugins[] = $plugin;
        } else {
            array_unshift($this->registeredPlugins, $plugin);
        }
        return $this;
    }

    public function plugins(Plugin ...$plugins): self
    {
        $this->registeredPlugins = array_values($plugins);
        return $this;
    }

    public function input(string $path): self
    {
        $this->inputPath = $path;
        return $this;
    }
    public function output(string $path): self
    {
        $this->outputPath = $path;
        return $this;
    }
    public function cache(string $path): self
    {
        $this->cachePath = $path;
        return $this;
    }
    public function includes(string $path): self
    {
        $this->includesPath = $path;
        return $this;
    }
    public function dataDirectory(string $path): self
    {
        $this->dataPath = $path;
        return $this;
    }
    public function title(string $title): self
    {
        $this->siteTitle = $title;
        return $this;
    }
    public function baseUrl(?string $url): self
    {
        $this->siteUrl = $url;
        return $this;
    }
    public function basePath(string $path): self
    {
        $this->siteBasePath = $path;
        return $this;
    }
    public function timezone(string $timezone): self
    {
        $this->siteTimezone = $timezone;
        return $this;
    }
    public function copy(string $source, string $destination): self
    {
        $this->copyMappings[] = [$source, $destination];
        return $this;
    }

    public function markdownHtml(bool $allow): self
    {
        $this->allowMarkdownHtml = $allow;
        return $this;
    }

    public function resolve(string $root): Settings
    {
        try {
            $root = Paths::absolute($root, getcwd() ?: '.');
            if (is_link(\Symfony\Component\Filesystem\Path::makeAbsolute($this->outputPath, $root))) {
                throw new ConfigurationException('Output cannot be a symlink.');
            }
            $input = Paths::absolute($this->inputPath, $root);
            $output = Paths::absolute($this->outputPath, $root);
            $cache = Paths::absolute($this->cachePath, $root);
            foreach ([$input, $output, $cache] as $i => $path) {
                foreach ([$input, $output, $cache] as $j => $other) {
                    if ($i !== $j && Paths::within($path, $other)) {
                        throw new ConfigurationException('Input, output and cache directories must not overlap.');
                    }
                }
            }
            if ($output === $root || $cache === $root || !Paths::within($output, $root) || !Paths::within($cache, $root)) {
                throw new ConfigurationException('Output and cache must be child directories of the project root.');
            }
            if (Paths::within($output, $root . '/.hanji') || Paths::within($root . '/.hanji', $output)) {
                throw new ConfigurationException('Output must not overlap Hanji state (.hanji).');
            }
            $state = Paths::absolute('.hanji/state', $root);
            if (!Paths::within($state, $root)) {
                throw new ConfigurationException('Hanji state must stay inside the project root.');
            }
            foreach ([$input, $output, $cache] as $path) {
                if (Paths::within($state, $path) || Paths::within($path, $state)) {
                    throw new ConfigurationException('Input, output and cache must not overlap Hanji state.');
                }
            }
            $includes = Paths::absolute($this->includesPath, $input);
            $data = Paths::absolute($this->dataPath, $input);
            if (!Paths::within($includes, $input) || !Paths::within($data, $input)) {
                throw new ConfigurationException('Include and data directories must be inside the input directory.');
            }
            new \DateTimeZone($this->siteTimezone);
            if ($this->siteUrl !== null && (filter_var($this->siteUrl, FILTER_VALIDATE_URL) === false || !in_array(parse_url($this->siteUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new ConfigurationException('baseUrl must be an HTTP(S) URL or null.');
            }
            if (preg_match('/[\\\\?#%\x00-\x1f]|(^|\/)\.\.?($|\/)/', $this->siteBasePath)) {
                throw new ConfigurationException('Invalid basePath.');
            }
            $basePath = '/' . trim($this->siteBasePath, '/') . '/';
            $basePath = $basePath === '//' ? '/' : $basePath;
            $copy = $this->mappings($this->copyMappings, $input);
            return new Settings($root, $input, $output, $cache, $includes, $data, ['title' => $this->siteTitle, 'baseUrl' => $this->siteUrl, 'basePath' => $basePath, 'timezone' => $this->siteTimezone], $copy, $this->registeredPlugins, $this->allowMarkdownHtml);
        } catch (ConfigurationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ConfigurationException($error->getMessage(), 0, $error);
        }
    }

    /**
     * @param list<array{string, string}> $rules
     * @return array<string, string>
     */
    private function mappings(array $rules, string $input): array
    {
        $result = [];
        foreach ($rules as [$source, $target]) {
            $source = Paths::absolute($source, $input);
            if (!Paths::within($source, $input) || isset($result[$source])) {
                throw new ConfigurationException("Invalid or duplicate source mapping: {$source}");
            }
            $result[$source] = Paths::relative($target);
        }
        return $result;
    }
}
