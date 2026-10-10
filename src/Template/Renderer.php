<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\Asset\AssetResolver;
use Hanji\Asset\Html;
use Hanji\BuildException;
use Hanji\Page;
use Hanji\Runtime\Context;
use Hanji\Runtime\ContextStack;
use Hanji\Settings;
use Hanji\Source\PreparedPage;
use Hanji\Support\Scripts;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Symfony\Component\Filesystem\Filesystem as Files;

final class Renderer
{
    private Factory $views;
    private Container $previousContainer;
    private BladeCompiler $compiler;
    private string $viewRoot;
    private Files $files;

    /**
     * @param array<string, string> $sources
     * @param array<string, string> $bodies
     */
    public function __construct(private readonly Settings $settings, array $sources, array $bodies = [])
    {
        $this->files = new Files();
        $this->viewRoot = $settings->cache . '/views';
        $this->files->mkdir([$this->viewRoot, $settings->cache . '/compiled', $settings->cache . '/scripts']);
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        Component::forgetFactory();
        $filesystem = new Filesystem();
        $this->compiler = new BladeCompiler($filesystem, $settings->cache . '/compiled', '', false);
        $this->compiler->directive('meta', static fn(string $expression): string => '<?php \\Hanji::meta(' . $expression . '); ?>');
        $resolver = new EngineResolver();
        $resolver->register('blade', fn(): CompilerEngine => new CompilerEngine($this->compiler, $filesystem));
        $includeRelative = substr($settings->includes, strlen($settings->input) + 1);
        $this->views = new Factory($resolver, new ViewFinder($filesystem, [$this->viewRoot . '/' . $includeRelative, $this->viewRoot], ['blade.php']), new Dispatcher($container));
        $this->views->setContainer($container);
        $container->instance('view', $this->views);
        $container->instance(FactoryContract::class, $this->views);
        $container->instance('blade.compiler', $this->compiler);
        foreach ($sources as $relative => $source) {
            if (str_ends_with($relative, '.blade.php')) {
                $this->prepare($source, $bodies[$source] ?? (string) file_get_contents($source));
            }
        }
        $components = $this->viewRoot . '/' . $includeRelative . '/components';
        if (is_dir($components)) {
            $this->compiler->anonymousComponentPath($components);
            $hash = hash('xxh128', $components);
            foreach ($sources as $relative => $source) {
                $prefix = $includeRelative . '/components/';
                if (str_starts_with($relative, $prefix) && str_ends_with($relative, '.blade.php')) {
                    $name = substr($relative, strlen($prefix), -10);
                    $name = (dirname($name) === '.' ? '' : dirname($name) . '/') . ltrim(basename($name), '@');
                    $name = str_replace('/', '.', $name);
                    $this->compiler->component($hash . '::' . $name, $name);
                }
            }
        }
    }

    public function close(): void
    {
        Component::forgetFactory();
        Component::flushCache();
        Container::setInstance($this->previousContainer);
    }

    /** @param array<string, list<Page>> $collections */
    public function render(PreparedPage $prepared, array $collections, AssetResolver $assets): string
    {
        $page = $prepared->page;
        $source = $prepared->instance->source;
        $scope = ['site' => $this->settings->site, 'page' => $page, 'collections' => $collections, 'hanji' => new Url($this->settings)];
        $context = new Context('body', $source, $page->data, $page->params, $page->extension, assets: $assets);
        try {
            $result = ContextStack::run($context, fn(): string => match ($prepared->instance->kind) {
                'blade' => $this->blade($source, $prepared->body, $scope),
                'php' => $this->php($source, $prepared->body, $scope, $page->extension === 'html'),
                'markdown' => Markdown::render($prepared->body, $source, $this->settings->markdownHtml),
                default => throw new BuildException('Unknown renderer.'),
            });
            if ($page->extension === 'html' && ($page->data['layout'] ?? true) !== false) {
                $directory = dirname($source);
                while (true) {
                    $layout = $directory . '/@layout.blade.php';
                    if (is_file($layout)) {
                        $layoutContext = new Context('layout', $layout, $page->data, $page->params, 'html', new HtmlString($result), $assets);
                        $result = ContextStack::run($layoutContext, fn(): string => $this->blade($layout, (string) file_get_contents($layout), $scope));
                    }
                    if ($directory === $this->settings->input) {
                        break;
                    }
                    $directory = dirname($directory);
                }
            }
            return $result;
        } catch (\Throwable $error) {
            throw new BuildException("{$source} [{$page->url}]: {$error->getMessage()}", 0, $error);
        }
    }

    /** @param array<string, mixed> $scope */
    private function blade(string $source, string $body, array $scope): string
    {
        $path = $this->prepare($source, $body);
        $this->views->flushState();
        $this->views->share($scope);
        try {
            return $this->views->file($path, $scope)->render();
        } finally {
            $this->views->flushState();
        }
    }

    /** @param array<string, mixed> $scope */
    private function php(string $source, string $body, array $scope, bool $html): string
    {
        $path = $this->settings->cache . '/scripts/' . hash('sha256', $source . $body) . '.php';
        $this->files->dumpFile($path, Scripts::original($html ? Html::instrument($body, $source) : $body, $source));
        $result = Scripts::require($path, $scope)[1];
        return $html ? \Hanji\Runtime\AssetRuntime::html($result, $source) : $result;
    }

    private function prepare(string $source, string $body): string
    {
        $path = $this->viewRoot . '/' . substr($source, strlen($this->settings->input) + 1);
        $this->files->dumpFile($path, $body);
        $this->compiler->origins[$path] = $source;
        return $path;
    }
}
