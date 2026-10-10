<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Asset\AssetResolver;
use Hanji\Asset\Scss;
use Hanji\Output\Publisher;
use Hanji\Output\Registry;
use Hanji\Runtime\AssetRuntime;
use Hanji\Runtime\Context;
use Hanji\Runtime\ContextStack;
use Hanji\Source\DataResolver;
use Hanji\Source\Declarations;
use Hanji\Source\Expander;
use Hanji\Source\Instance;
use Hanji\Source\Metadata;
use Hanji\Source\PreparedPage;
use Hanji\Source\Routes;
use Hanji\Source\Scanner;
use Hanji\Support\Maps;
use Hanji\Support\Paths;
use Hanji\Support\Scripts;
use Hanji\Template\Markdown;
use Hanji\Template\Renderer;
use Symfony\Component\Filesystem\Filesystem;

final class Builder
{
    public function build(Settings $settings, string $mode = 'prod'): BuildResult
    {
        if (!in_array($mode, ['prod', 'dev'], true)) {
            throw new ConfigurationException('Build mode must be prod or dev.');
        }
        $started = microtime(true);
        $publisher = new Publisher($settings);
        $renderer = null;
        try {
            $stage = $publisher->begin();
            $files = new Filesystem();
            $sources = (new Scanner())->scan($settings->input);
            $data = new DataResolver();
            $instances = (new Expander($settings, $data))->expand($data->global($settings->dataDirectory, $sources));
            $registry = new Registry();
            $declarations = new Declarations();
            $prepared = [];
            $manifest = [];
            $bodies = [];
            foreach ($instances as $instance) {
                $item = $this->prepare($instance, $settings, $data, $declarations);
                $registry->reserve($item->page->outputPath, $instance->inputPath . ' [' . $item->page->url . ']');
                $prepared[] = $item;
                $bodies[$instance->source] = $item->body;
                $manifest[$instance->source][] = ['url' => $item->page->url, 'params' => $item->page->params];
            }
            $assets = $this->assets($settings, $sources);
            foreach ($assets as $source => $target) {
                $registry->reserve($target, $source);
                if ($mode === 'dev' && Scanner::kind($source) === 'scss') {
                    $registry->reserve($target . '.map', $source . ' source map');
                }
                $manifest[$source] = [['url' => $settings->url($target), 'params' => []]];
            }
            $resolver = new AssetResolver($settings, $manifest);
            $collections = Collections::create(array_map(static fn(PreparedPage $item): Page => $item->page, $prepared));
            $renderer = new Renderer($settings, $sources, $bodies);
            foreach ($prepared as $item) {
                $files->dumpFile($stage . '/' . $item->page->outputPath, $renderer->render($item, $collections, $resolver));
            }
            foreach ($assets as $source => $target) {
                if (Scanner::kind($source) === 'scss') {
                    [$css, $map] = Scss::compile($source, $target, $settings, $mode);
                    $files->dumpFile($stage . '/' . $target, $css);
                    if ($map !== null) {
                        $files->dumpFile($stage . '/' . $target . '.map', $map);
                    }
                } elseif (str_ends_with($source, '.html')) {
                    $context = new Context('body', $source, assets: $resolver);
                    $content = ContextStack::run($context, static fn(): string => AssetRuntime::html((string) file_get_contents($source), $source));
                    $files->dumpFile($stage . '/' . $target, $content);
                } else {
                    $files->copy($source, $stage . '/' . $target);
                }
            }
            $publisher->publish();
            return new BuildResult(count($prepared), count($assets), $settings->output, microtime(true) - $started);
        } catch (BuildException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new BuildException($error->getMessage(), 0, $error);
        } finally {
            $renderer?->close();
            $publisher->close();
        }
    }

    private function prepare(Instance $instance, Settings $settings, DataResolver $data, Declarations $declarations): PreparedPage
    {
        try {
            $source = (string) file_get_contents($instance->source);
            $context = new Context('declaration', $instance->source, $instance->data, $instance->params);
            if ($instance->kind === 'markdown') {
                [$body, $frontMatter] = Markdown::extract($source, $instance->source);
                $sidecar = dirname($instance->source) . '/@' . basename($instance->source, '.md') . '.data';
                $context->data = Maps::merge(Maps::merge($context->data, $data->sidecar($sidecar)), $frontMatter);
            } else {
                $declaration = $declarations->extract($source, $instance->kind);
                $body = $declaration->body;
                if ($declaration->php !== '') {
                    $declarations->validateScope($declaration->php);
                    $script = $settings->cache . '/declarations/' . hash('sha256', $instance->source . $declaration->php) . '.php';
                    (new Filesystem())->dumpFile($script, Scripts::original('<?php ' . $declaration->php, $instance->source));
                    [, $output] = ContextStack::run($context, static fn(): array => Scripts::require($script, ['site' => $settings->site]));
                    if ($output !== '') {
                        throw new BuildException('Leading declarations must not emit output.');
                    }
                }
            }
            $metadata = Metadata::validate($context->data, $settings->site['timezone']);
            /** @var ?string $permalink */
            $permalink = $metadata['permalink'] ?? null;
            [$path, $url] = Routes::resolve($instance->routePath, $context->extension, $permalink);
            /** @var ?\DateTimeImmutable $date */
            $date = $metadata['date'] ?? null;
            return new PreparedPage($instance, new Page($instance->inputPath, $instance->routePath, $instance->params, $settings->url($url), $path, $context->extension, $date, $metadata), $body);
        } catch (\Throwable $error) {
            $hint = preg_match('/Undefined variable \$(page|collections|hanji)\b/', $error->getMessage()) ? ' Page URLs, collections and rendering helpers are unavailable while collecting declarations.' : '';
            throw new BuildException("{$instance->source}: {$error->getMessage()}{$hint}", 0, $error);
        }
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, string>
     */
    private function assets(Settings $settings, array $sources): array
    {
        foreach ($settings->scss as $source => $target) {
            if (!is_file($source) || Scanner::kind($source) !== 'scss' || pathinfo($target, PATHINFO_EXTENSION) !== 'css') {
                throw new ConfigurationException("SCSS mapping requires a public .scss input and a .css output: {$source}");
            }
        }
        foreach ($settings->copy as $source => $target) {
            if (!is_dir($source) && (!is_file($source) || Scanner::kind($source) !== 'static')) {
                throw new ConfigurationException("Copy mapping requires a static file or input directory: {$source}");
            }
        }
        $result = [];
        foreach ($sources as $relative => $source) {
            $kind = Scanner::kind($source);
            if ($kind === 'scss') {
                $result[$source] = $settings->scss[$source] ?? substr($relative, 0, -5) . '.css';
            } elseif ($kind === 'static') {
                $target = null;
                foreach ($settings->copy as $copySource => $copyTarget) {
                    if ($source === $copySource || (is_dir($copySource) && Paths::within($source, $copySource))) {
                        if ($target !== null) {
                            throw new ConfigurationException("Overlapping copy mappings for {$source}");
                        }
                        $target = $source === $copySource ? $copyTarget : $copyTarget . '/' . substr($source, strlen($copySource) + 1);
                    }
                }
                $result[$source] = $target ?? $relative;
            }
        }
        return $result;
    }
}
