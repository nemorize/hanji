<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Asset\AssetResolver;
use Hanji\Output\Publisher;
use Hanji\Output\Registry;
use Hanji\Plugin\AssetBuildPlugin;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\BuildOutput;
use Hanji\Plugin\BuildPreparation;
use Hanji\Plugin\BuildSession;
use Hanji\Plugin\PreparesBuild;
use Hanji\Plugin\Registry as Plugins;
use Hanji\Plugin\TransformPlugin;
use Hanji\Runtime\Context;
use Hanji\Runtime\ContextStack;
use Hanji\Source\DataResolver;
use Hanji\Source\Expander;
use Hanji\Source\Instance;
use Hanji\Source\Metadata;
use Hanji\Source\PreparedSource;
use Hanji\Source\Routes;
use Hanji\Source\Scanner;
use Hanji\Support\Maps;
use Hanji\Support\Paths;
use Illuminate\Support\HtmlString;
use Symfony\Component\Filesystem\Filesystem;

final class Builder
{
    /** @param (callable(BuildProgress): void)|null $progress */
    public function build(Settings $settings, ?callable $progress = null): BuildResult
    {
        $progress ??= static function (BuildProgress $event): void {};
        $started = microtime(true);
        $publisher = new Publisher($settings);
        $session = null;
        try {
            $stage = $publisher->begin();
            $files = new Filesystem();
            $progress(new BuildProgress('scanning'));
            $sources = (new Scanner())->scan($settings->input);
            $progress(new BuildProgress('preparing'));
            $data = new DataResolver();
            $plugins = new Plugins($settings->plugins);
            $global = $data->global($settings->dataDirectory, $sources);
            $session = new BuildSession($settings, $sources, $data);
            $instances = (new Expander($settings, $data, $plugins))->expand($global);
            $registry = new Registry();
            $prepared = [];
            $manifest = [];
            foreach ($instances as $instance) {
                $item = $this->prepare($instance, $session);
                $registry->reserve($item->page->outputPath, $instance->inputPath . ' [' . $item->page->url . ']');
                $prepared[] = $item;
                if ($item->preparation->body !== null) {
                    $session->bodies[$instance->source] = $item->preparation->body;
                }
                $manifest[$instance->source][] = ['url' => $item->page->url, 'params' => $item->page->params];
                $progress(new BuildProgress('preparing', count($prepared), count($instances), $instance->source));
            }
            $assets = $this->assets($settings, $sources, $plugins);
            $compiled = [];
            foreach ($assets as $source => $target) {
                $plugin = $plugins->builder($source);
                if ($plugin instanceof AssetBuildPlugin) {
                    $relative = substr($source, strlen($settings->input) + 1);
                    $instance = new Instance($relative, $target, $source, $plugin, $this->assetData($source, $session, $global), []);
                    $item = $this->prepare($instance, $session);
                    $target = $item->page->outputPath;
                    $compiled[$source] = $item;
                }
                $registry->reserve($target, $source);
                $assets[$source] = $target;
                $manifest[$source] = [['url' => $settings->url($target), 'params' => []]];
                $progress(new BuildProgress('preparing', source: $source));
            }
            $resolver = new AssetResolver($settings, $manifest);
            $collections = Collections::create(array_map(static fn(PreparedSource $item): Page => $item->page, $prepared));
            $total = count($prepared) + count($assets);
            $completed = 0;
            $progress(new BuildProgress('building', total: $total));
            foreach ($prepared as $item) {
                $files->dumpFile($stage . '/' . $item->page->outputPath, $this->render($item, $session, $plugins, $collections, $resolver));
                $progress(new BuildProgress('building', ++$completed, $total, $item->instance->source, $item->page->outputPath));
            }
            foreach ($assets as $source => $target) {
                if (isset($compiled[$source])) {
                    $content = $this->render($compiled[$source], $session, $plugins, $collections, $resolver);
                    $files->dumpFile($stage . '/' . $target, $content);
                } else {
                    $context = new BuildContext($settings, $session, $this->assetData($source, $session, $global), extension: strtolower(pathinfo($target, PATHINFO_EXTENSION)), collections: $collections, assets: $resolver);
                    $transforms = $this->matchingTransforms($source, $context, $plugins);
                    if ($transforms !== []) {
                        $content = $this->transform($source, $context, $transforms, $context->read($source));
                        $files->dumpFile($stage . '/' . $target, $content);
                    } else {
                        $files->copy($source, $stage . '/' . $target);
                    }
                }
                $progress(new BuildProgress('building', ++$completed, $total, $source, $target));
            }
            $progress(new BuildProgress('publishing'));
            $publisher->publish();
            return new BuildResult(count($prepared), count($assets), $settings->output, microtime(true) - $started);
        } catch (BuildException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new BuildException($error->getMessage(), 0, $error);
        } finally {
            $session?->close();
            $publisher->close();
        }
    }

    private function prepare(Instance $instance, BuildSession $session): PreparedSource
    {
        try {
            $context = new BuildContext($session->settings, $session, $instance->data, $instance->params);
            $plugin = $instance->plugin;
            $output = null;
            if ($plugin instanceof PreparesBuild) {
                $runtime = new Context('declaration', $instance->source, $instance->data, $instance->params);
                $preparation = ContextStack::run($runtime, fn(): BuildPreparation => $plugin->prepare($instance->source, $context));
            } else {
                $runtime = new Context('body', $instance->source, $instance->data, $instance->params, origins: $session->origins);
                $output = ContextStack::run($runtime, fn(): BuildOutput => $plugin->build($instance->source, $context));
                $preparation = new BuildPreparation($output->extension, context: $output->context, data: $output->data);
            }
            $metadata = Metadata::validate(Maps::merge($instance->data, $preparation->data), $session->settings->site['timezone']);
            if ($plugin instanceof AssetBuildPlugin) {
                $path = Routes::asset($instance->routePath, $preparation->extension);
                $url = '/' . $path;
            } else {
                /** @var ?string $permalink */
                $permalink = $metadata['permalink'] ?? null;
                [$path, $url] = Routes::resolve($instance->routePath, $preparation->extension, $permalink, $preparation->sourceExtension);
            }
            /** @var ?\DateTimeImmutable $date */
            $date = $metadata['date'] ?? null;
            $page = new Page($instance->inputPath, $instance->routePath, $instance->params, $session->settings->url($url), $path, $preparation->extension, $date, $metadata);
            return new PreparedSource($instance, $page, $preparation, $output);
        } catch (\Throwable $error) {
            $hint = preg_match('/Undefined variable \$(page|collections|hanji)\b/', $error->getMessage()) ? ' Page URLs, collections and rendering helpers are unavailable while collecting declarations.' : '';
            throw new BuildException("{$instance->source}: {$error->getMessage()}{$hint}", 0, $error, source: $instance->source, hint: $hint === '' ? null : trim($hint));
        }
    }

    /** @param array<string, list<Page>> $collections */
    private function render(PreparedSource $item, BuildSession $session, Plugins $plugins, array $collections, AssetResolver $assets): string
    {
        $page = $item->page;
        $source = $item->instance->source;
        $context = new BuildContext($session->settings, $session, $page->data, $page->params, $item->preparation->context, $item->preparation->body, $page->extension, $page, $collections, $assets);
        try {
            if ($item->output !== null) {
                $output = new BuildOutput($item->output->content, $item->output->extension);
            } else {
                $runtime = new Context('body', $source, $context->data, $context->params, $context->extension, origins: $session->origins);
                $output = ContextStack::run($runtime, fn(): BuildOutput => $item->instance->plugin->build($source, $context));
                $output = $this->validatePreparedOutput($page, $output, $session->settings);
            }
            $context = $context->withOutput($output);
            if ($output->extension === 'html' && !$item->instance->plugin instanceof AssetBuildPlugin && ($context->data['layout'] ?? true) !== false) {
                foreach ($plugins->layouts() as $plugin) {
                    if (($context->data['layout'] ?? true) === false) {
                        break;
                    }
                    $runtime = new Context('layout', $source, $context->data, $context->params, 'html', new HtmlString($output->content), $session->origins);
                    $output = ContextStack::run($runtime, fn(): BuildOutput => $plugin->layout($source, $context, $output));
                    $output = $this->validatePreparedOutput($page, $output, $session->settings);
                    $context = $context->withOutput($output);
                }
            }
            return $this->transform($source, $context, $this->matchingTransforms($source, $context, $plugins), $output->content);
        } catch (\Throwable $error) {
            throw new BuildException("{$source} [{$page->url}]: {$error->getMessage()}", 0, $error, source: $source, url: $page->url);
        }
    }

    /** @return list<TransformPlugin> */
    private function matchingTransforms(string $path, BuildContext $context, Plugins $plugins): array
    {
        $runtime = new Context('transform', $path, $context->data, $context->params, $context->extension, origins: $context->session->origins);
        try {
            return ContextStack::run($runtime, static fn(): array => array_values(array_filter($plugins->transforms(), static fn(TransformPlugin $plugin): bool => $plugin->supports($path, $context))));
        } catch (\Throwable $error) {
            throw new BuildException("{$path}: transform selection failed: {$error->getMessage()}", 0, $error, source: $path);
        }
    }

    /** @param list<TransformPlugin> $transforms */
    private function transform(string $path, BuildContext $context, array $transforms, string $content): string
    {
        $runtime = new Context('transform', $path, $context->data, $context->params, $context->extension, origins: $context->session->origins);
        try {
            $content = ContextStack::run($runtime, static function () use ($path, $context, $transforms, $content): string {
                foreach ($transforms as $plugin) {
                    $content = $plugin->transform($path, $context, $content);
                }
                return $content;
            });
            return $context->session->origins->strip($content);
        } catch (\Throwable $error) {
            throw new BuildException("{$path}: transform failed: {$error->getMessage()}", 0, $error, source: $path);
        }
    }

    private function validatePreparedOutput(Page $page, BuildOutput $output, Settings $settings): BuildOutput
    {
        if ($output->extension !== $page->extension) {
            throw new BuildException('Output extension changed after preparation. Declare it in prepare().');
        }
        $data = Metadata::validate($output->data, $settings->site['timezone']);
        foreach (['permalink', 'date', 'tags', 'title'] as $key) {
            if (array_key_exists($key, $data) && (!array_key_exists($key, $page->data) || $data[$key] != $page->data[$key])) {
                throw new BuildException("{$key} changed after preparation. Declare collection and routing data in prepare().");
            }
        }
        return new BuildOutput($output->content, $output->extension, $output->context, $data);
    }

    /**
     * @param array<string, string> $sources
     * @return array<string, string>
     */
    private function assets(Settings $settings, array $sources, Plugins $plugins): array
    {
        foreach ($settings->copy as $source => $target) {
            $plugin = $plugins->builder($source);
            if (!is_dir($source) && (!is_file($source) || Scanner::special($source) || ($plugin !== null && !$plugin instanceof AssetBuildPlugin))) {
                throw new ConfigurationException("Copy mapping requires a static file, asset plugin input or input directory: {$source}");
            }
        }
        $result = [];
        foreach ($sources as $relative => $source) {
            $plugin = $plugins->builder($source);
            if (Scanner::special($source) || ($plugin !== null && !$plugin instanceof AssetBuildPlugin)) {
                continue;
            }
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
        return $result;
    }

    /**
     * @param array<string, mixed> $global
     * @return array<string, mixed>
     */
    private function assetData(string $path, BuildSession $session, array $global): array
    {
        $directories = [];
        for ($directory = dirname($path);; $directory = dirname($directory)) {
            $directories[] = $directory;
            if ($directory === $session->settings->input) {
                break;
            }
        }
        foreach (array_reverse($directories) as $directory) {
            $global = Maps::merge($global, $session->data->directory($directory));
        }
        return $global;
    }
}
