<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Builder;
use Hanji\BuildException;
use Hanji\Config;
use Hanji\Plugin\Build\BladeBuildPlugin;
use Hanji\Plugin\Build\MarkdownBuildPlugin;
use Hanji\Plugin\Build\PhpBuildPlugin;
use Hanji\Plugin\Build\ScssBuildPlugin;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\Transform\RelativePathTransformPlugin;
use Hanji\Plugin\TransformPlugin;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TransformTest extends TestCase
{
    private string $root;
    private Filesystem $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hanji-transform-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->mkdir($this->root . '/site');
    }

    protected function tearDown(): void
    {
        $this->files->remove($this->root);
    }

    public function testRelativePathsCanBeDisabledForEveryHtmlBuildFormatAndCopiedHtml(): void
    {
        $this->write('site/@layout.blade.php', '<main><img src="./missing-layout.svg">{{ Hanji::children() }}</main>');
        $this->write('site/index.blade.php', '<img src="./missing-blade.svg">');
        $this->write('site/native.php', '<?php echo \'<img src="./missing-php.svg">\';');
        $this->write('site/post.md', '![Image](./missing-markdown.svg)');
        $this->write('site/static.html', '<img src="./missing-static.svg">');
        $config = (new Config())->plugins(new BladeBuildPlugin(), new PhpBuildPlugin(), new MarkdownBuildPlugin());
        $this->build($config);
        foreach (['index.html' => 'blade', 'native/index.html' => 'php', 'post/index.html' => 'markdown'] as $path => $format) {
            $html = $this->read($path);
            self::assertStringContainsString('src="./missing-' . $format . '.svg"', $html);
            self::assertStringContainsString('src="./missing-layout.svg"', $html);
            self::assertStringNotContainsString('hanji-origin:', $html);
        }
        self::assertSame('<img src="./missing-static.svg">', $this->read('static.html'));
        $previous = $this->read('index.html');
        try {
            $this->build($config->plugin(new RelativePathTransformPlugin()));
            self::fail('The registered transform must validate relative asset references.');
        } catch (BuildException $error) {
            self::assertStringContainsString('Missing asset', $error->getMessage());
        }
        self::assertSame($previous, $this->read('index.html'));
    }

    public function testTransformsRunOnceAfterLayoutsInRegistrationOrderWithDynamicContext(): void
    {
        $first = new class implements TransformPlugin {
            /** @var list<string> */
            public array $calls = [];

            public function supports(string $path, BuildContext $context): bool
            {
                return $context->extension === 'html';
            }

            public function transform(string $path, BuildContext $context, string $content): string
            {
                self::check($content);
                $this->calls[] = $context->params['name'];
                return (preg_replace('/<!--.*?-->/s', '', $content) ?? $content) . '<span>first</span>';
            }

            private static function check(string $content): void
            {
                if (!str_contains($content, '</main>') || !str_contains($content, 'src="/docs/static/%5Bname%5D/cover.svg"')) {
                    throw new BuildException('Transforms must see the final layout and resolved references.');
                }
            }
        };
        $second = new class implements TransformPlugin {
            /** @var list<string> */
            public array $calls = [];

            public function supports(string $path, BuildContext $context): bool
            {
                return $context->extension === 'html';
            }

            public function transform(string $path, BuildContext $context, string $content): string
            {
                if (!str_ends_with($content, '<span>first</span>')) {
                    throw new BuildException('The previous transform was not applied.');
                }
                $this->calls[] = $context->params['name'];
                return $content . '<span>second</span>';
            }
        };
        $this->write('site/@layout.blade.php', '<main>{{ Hanji::children() }}</main>');
        $this->write('site/[name]/@params.php', '<?php return ["one", "two"];');
        $this->write('site/[name]/index.blade.php', '<img src="./cover.svg">');
        $this->write('site/[name]/cover.svg', '<svg/>');
        $this->build((new Config())->plugin($first)->plugin($second)->copy('[name]', 'static/[name]')->basePath('/docs/'));
        self::assertSame(['one', 'two'], $first->calls);
        self::assertSame(['one', 'two'], $second->calls);
        self::assertSame('<main><img src="/docs/static/%5Bname%5D/cover.svg"></main><span>first</span><span>second</span>', $this->read('one/index.html'));
        self::assertSame($this->read('one/index.html'), $this->read('two/index.html'));
    }

    public function testCopiedAndCompiledAssetsAndTextOutputsUseTheSameTransformPipeline(): void
    {
        $plugin = new class implements TransformPlugin {
            /** @var list<string> */
            public array $selections = [];
            /** @var list<string> */
            public array $calls = [];

            public function supports(string $path, BuildContext $context): bool
            {
                $this->selections[] = basename($path);
                return in_array($context->extension, ['css', 'txt'], true);
            }

            public function transform(string $path, BuildContext $context, string $content): string
            {
                $this->calls[] = basename($path);
                return $content . '|transformed';
            }
        };
        $this->write('site/style.css', 'body {}');
        $this->write('site/theme.scss', '.paper { color: #123456; }');
        $this->write('site/robots.php', '<?php Hanji::extension("txt"); echo "robots";');
        $this->write('site/image.png', "\0\xffPNG");
        $this->build((new Config())->plugin(new ScssBuildPlugin())->plugin($plugin));
        self::assertSame('body {}|transformed', $this->read('style.css'));
        self::assertStringEndsWith('|transformed', $this->read('theme.css'));
        self::assertSame('robots|transformed', $this->read('robots.txt'));
        self::assertSame("\0\xffPNG", $this->read('image.png'));
        self::assertSame(['robots.php', 'style.css', 'theme.scss'], $plugin->calls);
        self::assertSame(['robots.php', 'image.png', 'style.css', 'theme.scss'], $plugin->selections);
    }

    public function testEscapedRenderedMarkupRetainsEscapingAndNeverLeaksOriginMarkers(): void
    {
        $this->write('site/_includes/@header.blade.php', '<img src="./not-a-real-asset.svg">');
        $this->write('site/index.blade.php', <<<'BLADE'
            @php ob_start(); @endphp
            @include('header')
            @php $markup = ob_get_clean(); @endphp
            {{ $markup }}
            {{ e($markup) }}
            BLADE);
        $this->build();
        $html = $this->read('index.html');
        self::assertStringContainsString('&lt;img src=&quot;./not-a-real-asset.svg&quot;&gt;', $html);
        self::assertStringContainsString('&amp;lt;img src=&amp;quot;./not-a-real-asset.svg&amp;quot;&amp;gt;', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('hanji-origin:', $html);
    }

    public function testMarkdownTemplateCharactersAreLiteralInTextAndImageAttributes(): void
    {
        $this->write('site/index.md', "Literal {{ in a paragraph.\n\n![{{](./cover.svg)\n\n![{{ example }}](./cover.svg)");
        $this->write('site/cover.svg', '<svg/>');
        $this->build((new Config())->copy('cover.svg', 'public/cover.svg'));
        $html = $this->read('index.html');
        self::assertSame(2, substr_count($html, 'src="/public/cover.svg"'));
        self::assertStringContainsString('alt="{{"', $html);
        self::assertStringContainsString('alt="{{ example }}"', $html);
    }

    public function testFailingTransformsPreserveOutputAndRestoreRuntimeState(): void
    {
        $this->write('site/index.blade.php', 'original');
        $this->build();
        $this->write('site/index.blade.php', 'changed');
        $plugin = new class implements TransformPlugin {
            public function supports(string $path, BuildContext $context): bool
            {
                return $context->extension === 'html';
            }

            public function transform(string $path, BuildContext $context, string $content): string
            {
                throw new BuildException('Transform failed deliberately');
            }
        };
        $container = Container::getInstance();
        $buffers = ob_get_level();
        try {
            $this->build((new Config())->plugin($plugin));
            self::fail('Transform failure must abort the build.');
        } catch (BuildException $error) {
            self::assertStringContainsString('Transform failed deliberately', $error->getMessage());
        }
        self::assertSame('original', $this->read('index.html'));
        self::assertSame([], glob($this->root . '/.hanji-build-*'));
        self::assertSame($container, Container::getInstance());
        self::assertSame($buffers, ob_get_level());
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
