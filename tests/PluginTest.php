<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Builder;
use Hanji\BuildException;
use Hanji\Config;
use Hanji\ConfigLoader;
use Hanji\Plugin\AssetBuildPlugin;
use Hanji\Plugin\Build\BladeBuildPlugin;
use Hanji\Plugin\Build\MarkdownBuildPlugin;
use Hanji\Plugin\Build\ScssBuildPlugin;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\BuildOutput;
use Hanji\Plugin\BuildPlugin;
use Hanji\Plugin\BuildPreparation;
use Hanji\Plugin\PreparesBuild;
use Hanji\Plugin\LayoutPlugin;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PluginTest extends TestCase
{
    private string $root;
    private Filesystem $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hanji-plugin-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->mkdir($this->root . '/site');
    }

    protected function tearDown(): void
    {
        $this->files->remove($this->root);
    }

    public function testBuildOutputSuppliesDynamicRoutesDataContextAndCollections(): void
    {
        $plugin = new class implements BuildPlugin {
            /** @var list<string> */
            public array $calls = [];

            public function supports(string $path): bool
            {
                return str_ends_with($path, '.note');
            }

            public function build(string $path, BuildContext $context): BuildOutput
            {
                $name = $context->params['name'];
                $this->calls[] = $name;
                return new BuildOutput(
                    '<h1>' . $context->read($path) . '</h1><img src="./cover.svg">',
                    context: ['label' => $name],
                    data: ['title' => strtoupper($name), 'tags' => ['products'], 'permalink' => '/catalog/' . $name . '/'],
                );
            }
        };
        $this->write('site/@layout.blade.php', '<main><b>{{ $label ?? "none" }}</b>{{ Hanji::children() }}</main>');
        $this->write('site/products/[name]/@params.php', '<?php return ["one", "two"];');
        $this->write('site/products/[name]/item.note', 'Product');
        $this->write('site/products/[name]/@private.note', 'Must not execute');
        $this->write('site/products/[name]/cover.svg', '<svg/>');
        $this->write('site/z.blade.php', '@foreach($collections["products"] as $item)<a href="{{ $item->url }}">{{ $item->data["title"] }}</a>@endforeach');
        $result = $this->build((new Config())->plugin($plugin));
        self::assertSame(3, $result->pages);
        self::assertSame(1, $result->assets);
        self::assertSame(['one', 'two'], $plugin->calls);
        self::assertStringContainsString('<b>one</b><h1>Product</h1><img src="/products/%5Bname%5D/cover.svg">', $this->read('catalog/one/index.html'));
        self::assertStringContainsString('<b>two</b>', $this->read('catalog/two/index.html'));
        $collection = $this->read('z/index.html');
        self::assertStringContainsString('<b>none</b>', $collection);
        self::assertStringContainsString('<a href="/catalog/one/">ONE</a>', $collection);
        self::assertStringContainsString('<a href="/catalog/two/">TWO</a>', $collection);
        self::assertFileDoesNotExist($this->root . '/_site/products/[name]/@private.note');
    }

    public function testPreparationProvidesVariablesAndBuildReceivesPageCollectionsAndAssets(): void
    {
        $plugin = new class implements PreparesBuild {
            public int $preparations = 0;
            public int $builds = 0;

            public function supports(string $path): bool
            {
                return str_ends_with($path, '.report');
            }

            public function prepare(string $path, BuildContext $context): BuildPreparation
            {
                ++$this->preparations;
                return new BuildPreparation(body: strtoupper($context->read($path)), context: ['label' => 'prepared'], data: ['title' => 'Report', 'tags' => ['reports'], 'date' => '2026-10-11']);
            }

            public function build(string $path, BuildContext $context): BuildOutput
            {
                ++$this->builds;
                $post = $context->collections['posts'][0];
                $label = $context->context['label'];
                $content = (is_string($label) ? $label : '') . ':' . $context->read($path) . ':' . $context->page?->url . ':' . $post->url;
                $content .= '<img src="./cover.svg">';
                return new BuildOutput($content, context: ['label' => 'built'], data: [...$context->data, 'details' => ['ready' => true]]);
            }
        };
        $this->write('site/post.md', "---\ntitle: Post\ntags: [posts]\n---\nPost");
        $this->write('site/report.report', 'body');
        $this->write('site/cover.svg', '<svg/>');
        $this->write('site/@layout.blade.php', '<main>{{ $label ?? "none" }}:{{ (Hanji::meta("details", []))["ready"] ?? false ? "ready" : "waiting" }}{{ Hanji::children() }}</main>');
        $this->build((new Config())->plugin($plugin));
        self::assertSame(1, $plugin->preparations);
        self::assertSame(1, $plugin->builds);
        self::assertSame('<main>built:readyprepared:BODY:/report/:/post/<img src="/cover.svg"></main>', $this->read('report/index.html'));
    }

    public function testPluginsCanOverrideBuiltinsAndReplaceTheDefaultSet(): void
    {
        $override = new class implements BuildPlugin {
            public function supports(string $path): bool
            {
                return str_ends_with($path, '.php');
            }

            public function build(string $path, BuildContext $context): BuildOutput
            {
                return new BuildOutput('overridden', 'txt');
            }
        };
        $php = '<?php throw new Exception("Must not execute");';
        $this->write('site/page.php', $php);
        $this->write('site/index.md', '# Markdown');
        $this->write('site/@layout.blade.php', '<main>{{ Hanji::children() }}</main>');
        $this->build((new Config())->plugin(new BladeBuildPlugin())->plugin($override));
        self::assertSame('overridden', $this->read('page.txt'));
        self::assertSame("<main><h1>Markdown</h1>\n</main>", $this->read('index.html'));
        $this->build((new Config())->plugins(new MarkdownBuildPlugin()));
        self::assertSame($php, $this->read('page.php'));
        self::assertSame("<h1>Markdown</h1>\n", $this->read('index.html'));
        self::assertFileDoesNotExist($this->root . '/_site/page.txt');
        self::assertFileDoesNotExist($this->root . '/_site/@layout.blade.php');
    }

    public function testScssIsAnOptInAssetPluginAndDynamicAssetsCompileOnce(): void
    {
        $plugin = new class implements AssetBuildPlugin {
            public int $builds = 0;

            public function supports(string $path): bool
            {
                return str_ends_with($path, '.scss');
            }

            public function build(string $path, BuildContext $context): BuildOutput
            {
                ++$this->builds;
                return (new ScssBuildPlugin())->build($path, $context);
            }
        };
        $this->write('site/products/[name]/@params.php', '<?php return ["one", "two"];');
        $this->write('site/products/[name]/index.blade.php', '<link rel="stylesheet" href="./app.scss?v=1#css">');
        $this->write('site/products/[name]/@tokens.scss', '$ink: #123456;');
        $this->write('site/products/[name]/app.scss', '@import "./@tokens.scss"; .paper { color: $ink; &:hover { color: white; } }');
        $result = $this->build((new Config())->plugin($plugin)->copy('products', 'static/products')->basePath('/docs/'));
        self::assertSame(2, $result->pages);
        self::assertSame(1, $result->assets);
        self::assertSame(1, $plugin->builds);
        self::assertSame('<link rel="stylesheet" href="/docs/static/products/%5Bname%5D/app.css?v=1#css">', $this->read('products/one/index.html'));
        self::assertSame($this->read('products/one/index.html'), $this->read('products/two/index.html'));
        self::assertStringContainsString('.paper:hover', $this->read('static/products/[name]/app.css'));
        self::assertFileDoesNotExist($this->root . '/_site/static/products/[name]/@tokens.scss');
        self::assertFileDoesNotExist($this->root . '/_site/static/products/one/app.css');
        self::assertFileDoesNotExist($this->root . '/_site/static/products/[name]/app.scss');
    }

    public function testLayoutPluginsPassContextAndDataToTheNextLayout(): void
    {
        $plugin = new class implements LayoutPlugin {
            public function layout(string $path, BuildContext $context, BuildOutput $output): BuildOutput
            {
                return new BuildOutput('<aside>' . \Hanji::children()->toHtml() . '<img src="./logo.svg"></aside>', context: ['label' => 'layout'], data: ['ready' => true]);
            }
        };
        $this->write('site/index.php', 'body');
        $this->write('site/logo.svg', '<svg/>');
        $this->write('site/@layout.blade.php', '<main>{{ $label }}:{{ Hanji::meta("ready") ? "ready" : "waiting" }}{{ Hanji::children() }}</main>');
        $this->build((new Config())->plugin($plugin));
        self::assertSame('<main>layout:ready<aside>body<img src="/logo.svg"></aside></main>', $this->read('index.html'));
    }

    public function testConfigObjectCanRegisterScssAndAFileCopyMapping(): void
    {
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->plugin(new Hanji\\Plugin\\Build\\ScssBuildPlugin(compressed: false))->copy("styles/app.scss", "public/main.css");');
        $this->write('site/styles/app.scss', '.paper { color: #123456; }');
        $this->write('site/index.php', '<link rel="stylesheet" href="./styles/app.scss">');
        (new Builder())->build((new ConfigLoader())->load($this->root));
        self::assertSame('<link rel="stylesheet" href="/public/main.css">', $this->read('index.html'));
        self::assertStringContainsString("\n", $this->read('public/main.css'));
    }

    public function testCompiledAssetCollisionPreservesExistingOutput(): void
    {
        $this->write('site/index.php', 'original');
        $this->build();
        $this->write('site/app.scss', 'body { color: red; }');
        $this->write('site/app.css', 'body { color: blue; }');
        try {
            $this->build((new Config())->plugin(new ScssBuildPlugin()));
            self::fail('Compiled assets must participate in collision detection.');
        } catch (BuildException $error) {
            self::assertStringContainsString('collision', $error->getMessage());
        }
        self::assertSame('original', $this->read('index.html'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function pluginFailures(): iterable
    {
        yield 'exception' => ['throw', 'Plugin failed'];
        yield 'changed extension' => ['extension', 'Output extension changed'];
        yield 'late metadata' => ['metadata', 'changed after preparation'];
        yield 'reserved context' => ['context', 'reserved plugin context'];
    }

    #[DataProvider('pluginFailures')]
    public function testFailuresRestoreOutputBuffersAndTheBladeContainer(string $failure, string $message): void
    {
        $this->write('site/index.php', 'original');
        $this->build();
        $plugin = new class ($failure) implements PreparesBuild {
            public function __construct(private readonly string $failure) {}

            public function supports(string $path): bool
            {
                return str_ends_with($path, '.broken');
            }

            public function prepare(string $path, BuildContext $context): BuildPreparation
            {
                return new BuildPreparation();
            }

            public function build(string $path, BuildContext $context): BuildOutput
            {
                return match ($this->failure) {
                    'extension' => new BuildOutput('invalid', 'json'),
                    'metadata' => new BuildOutput('invalid', data: ['title' => 'Late title']),
                    'context' => new BuildOutput('invalid', context: ['page' => 'override']),
                    default => throw new BuildException('Plugin failed'),
                };
            }
        };
        $this->write('site/a.blade.php', '<p>Rendered first</p>');
        $this->write('site/z.broken', 'broken');
        $container = Container::getInstance();
        $buffers = ob_get_level();
        try {
            $this->build((new Config())->plugin($plugin));
            self::fail('Invalid plugin output must fail the build.');
        } catch (BuildException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame('original', $this->read('index.html'));
        self::assertSame($container, Container::getInstance());
        self::assertSame($buffers, ob_get_level());
        self::assertSame([], glob($this->root . '/.hanji-build-*'));
        $this->expectException(BuildException::class);
        \Hanji::meta();
    }

    private function write(string $path, string $content): void
    {
        $this->files->dumpFile($this->root . '/' . $path, $content);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($this->root . '/_site/' . $path);
        return $content === false ? throw new \RuntimeException('Missing output: ' . $path) : $content;
    }

    private function build(?Config $config = null): \Hanji\BuildResult
    {
        return (new Builder())->build(($config ?? new Config())->resolve($this->root));
    }
}
