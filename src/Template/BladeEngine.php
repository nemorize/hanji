<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\Settings;
use Hanji\Support\Scripts;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Component;
use Illuminate\View\Engines\EngineResolver;
use Symfony\Component\Filesystem\Filesystem as Files;

final class BladeEngine
{
    private ViewFactory $views;
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
        $this->views = new ViewFactory($resolver, new ViewFinder($filesystem, [$this->viewRoot . '/' . $includeRelative, $this->viewRoot], ['blade.php']), new Dispatcher($container));
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

    /** @param array<string, mixed> $scope */
    public function render(string $source, string $body, array $scope): string
    {
        $path = $this->prepare($source, $body);
        $this->views->flushState();
        $this->views->scope($scope);
        try {
            return $this->views->file($path, $scope)->render();
        } finally {
            $this->views->flushState();
            $this->views->scope([]);
        }
    }

    private function prepare(string $source, string $body): string
    {
        $path = $this->viewRoot . '/' . substr($source, strlen($this->settings->input) + 1);
        $this->files->dumpFile($path, $body);
        $this->compiler->origins[$path] = $source;
        return $path;
    }
}
