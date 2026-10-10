<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Builder;
use Hanji\BuildException;
use Hanji\Config;
use Hanji\ConfigLoader;
use Hanji\ConfigurationException;
use Hanji\Console;
use Hanji\Initializer;
use Hanji\Output\Publisher;
use Hanji\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class BuildTest extends TestCase
{
    private string $root;
    private Filesystem $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hanji-test-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->mkdir($this->root . '/site');
    }

    protected function tearDown(): void
    {
        $this->files->remove($this->root);
    }

    public function testCompleteSiteBuild(): void
    {
        $this->write('site/@layout.blade.php', '<html><link rel="stylesheet" href="./assets/scss/app.scss">@include("partials.header"){{ Hanji::children() }}</html>');
        $this->write('site/_includes/partials/@header.blade.php', '<header><img src="../../assets/images/logo.svg"></header>');
        $this->write('site/index.blade.php', '@meta(["title" => "Home"])<h1>{{ Hanji::meta("title") }}</h1>');
        $this->write('site/about.php', '<?php Hanji::meta(["title" => "About"]); ?><h1><?= Hanji::meta("title") ?></h1>');
        $this->write('site/feed.php', '<?php Hanji::extension("json"); echo json_encode(["title" => $site["title"]], JSON_THROW_ON_ERROR);');
        $this->write('site/robots.php', '<?php Hanji::extension("txt"); echo "User-agent: *\\nAllow: /\\n";');
        $this->write('site/sitemap.blade.php', <<<'BLADE'
            @php Hanji::extension('xml'); @endphp
            {!! '<?xml version="1.0" encoding="UTF-8"?>' !!}<urlset></urlset>
            BLADE);
        $this->write('site/posts/hello.md', "---\ntitle: Hello\ntags: [posts]\n---\n\n![Cover](./cover.svg)\n\n`{{ example }}`\n");
        $this->write('site/posts/@layout.blade.php', '<article>{{ Hanji::children() }}</article>');
        $this->write('site/products/[foo]/@params.php', '<?php return ["hanji", "washi"];');
        $this->write('site/products/[foo]/index.blade.php', '<h1>{{ Hanji::params("foo") }}</h1><img src="./cover.svg">');
        $this->write('site/products/[foo]/releases/[version]/@params.php', '<?php return Hanji::params("foo") === "hanji" ? ["0.1", "0.2"] : ["1.0"];');
        $this->write('site/products/[foo]/releases/[version]/index.blade.php', '<p>{{ Hanji::params("version") }}</p><img src="../../cover.svg">');
        $this->write('site/assets/scss/@tokens.scss', '$ink: #123456;');
        $this->write('site/assets/scss/app.scss', '@import "./@tokens.scss"; body { color: $ink; }');
        foreach (['assets/images/logo.svg', 'posts/cover.svg', 'products/[foo]/cover.svg'] as $path) {
            $this->write('site/' . $path, '<svg/>');
        }
        $this->write('site/unreferenced.txt', 'Unreferenced asset');
        $this->write('site/.visible.txt', 'Hidden asset');
        $this->write('site/@notes.md', 'Private notes');
        $this->write('site/assets/images/@private.svg', '<svg/>');
        $result = $this->build((new Config())->scss('assets/scss/app.scss', 'assets/css/app.css')->copy('assets/images', 'static/images'));
        self::assertSame(11, $result->pages);
        self::assertSame(6, $result->assets);
        self::assertCount(17, $this->outputs());
        self::assertStringContainsString('href="/assets/css/app.css"', $this->readOutput('index.html'));
        self::assertStringContainsString('src="/static/images/logo.svg"', $this->readOutput('about/index.html'));
        self::assertStringContainsString('src="/posts/cover.svg"', $this->readOutput('posts/hello/index.html'));
        self::assertStringContainsString('<code>{{ example }}</code>', $this->readOutput('posts/hello/index.html'));
        self::assertStringContainsString('src="/products/%5Bfoo%5D/cover.svg"', $this->readOutput('products/hanji/releases/0.1/index.html'));
        self::assertFileDoesNotExist($this->root . '/_site/products/hanji/cover.svg');
        self::assertStringStartsWith('<?xml version=', $this->readOutput('sitemap.xml'));
        self::assertStringStartsWith('{', $this->readOutput('feed.json'));
        self::assertSame("User-agent: *\nAllow: /\n", $this->readOutput('robots.txt'));
        foreach ($this->outputs() as $path) {
            self::assertFalse(str_starts_with(basename($path), '@'));
            self::assertFalse(str_ends_with($path, '.php'));
        }
    }

    public function testDeclarationAndNestedProviderRunOnceAndAssetsRemainStatic(): void
    {
        $this->write('site/[product]/@params.php', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/../../calls', 'P', FILE_APPEND);
            return [['value' => '한지', 'data' => ['title' => 'Paper']], ['value' => 'washi', 'data' => ['title' => 'Washi']]];
            PHP);
        $this->write('site/[product]/[version]/@params.php', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/../../../calls', 'V', FILE_APPEND);
            return Hanji::params('product') === '한지' ? [1, 2] : [3];
            PHP);
        $this->write('site/[product]/[version]/index.blade.php', <<<'BLADE'
            @php
            file_put_contents(__DIR__ . '/../../../calls', 'M', FILE_APPEND);
            Hanji::meta(['title' => Hanji::meta('title') . Hanji::params('version'), 'tags' => ['products']]);
            @endphp
            <h1>{{ Hanji::meta('title') }}</h1>
            <img src="../cover.svg?width=1&amp;height=2#main">
            @php file_put_contents(__DIR__ . '/../../../calls', 'B', FILE_APPEND); @endphp
            BLADE);
        $this->write('site/[product]/cover.svg', '<svg/>');
        $this->write('site/@ignore.php', '<?php throw new Exception("Must never execute");');
        $this->write('site/[empty]/@params.php', '<?php return [];');
        $this->write('site/[empty]/[nested]/@params.php', '<?php throw new Exception("Empty branch executed");');
        $this->write('site/[empty]/index.php', '<?php throw new Exception("Empty page executed");');
        $this->write('site/[empty]/photo.png', 'PNG');
        $result = $this->build((new Config())->basePath('/docs/'));
        self::assertSame(3, $result->pages);
        self::assertSame(2, $result->assets);
        self::assertSame('PVVMMMBBB', file_get_contents($this->root . '/calls'));
        self::assertStringContainsString('<h1>Paper1</h1>', $this->readOutput('한지/1/index.html'));
        self::assertStringContainsString('/docs/%5Bproduct%5D/cover.svg?width=1&amp;height=2#main', $this->readOutput('한지/1/index.html'));
        self::assertFileExists($this->root . '/_site/[empty]/photo.png');
        self::assertCount(5, $this->outputs());
    }

    public function testLayoutsIncludesComponentsAndExtendsKeepTheirOwnOrigins(): void
    {
        $this->write('site/assets/root.svg', '<svg/>');
        $this->write('site/_includes/local.svg', '<svg/>');
        $this->write('site/_includes/components/panel.svg', '<svg/>');
        $this->write('site/_includes/components/@panel.blade.php', '<aside><img src="./panel.svg">{{ $slot }}{{ Hanji::children() }}</aside>');
        $this->write('site/_includes/@header.blade.php', '<header><img src="./local.svg">{{ Hanji::meta("title") }}</header>');
        $this->write('site/_includes/@frame.blade.php', '<section><img src="./local.svg">@yield("body")</section>');
        $this->write('site/@layout.blade.php', '<html><img src="./assets/root.svg">@include("header")<x-panel>{{ Hanji::children() }}</x-panel></html>');
        $this->write('site/nested/@layout.blade.php', '<article>{{ Hanji::children() }}</article>');
        $this->write('site/nested/index.blade.php', <<<'BLADE'
            @meta(['title' => 'Nested', 'permalink' => '/elsewhere/'])
            @extends('frame')
            @section('body')<strong>{{ Hanji::meta('title') }}</strong>@endsection
            BLADE);
        $this->write('site/plain.blade.php', '@meta(["layout" => false])<p>Plain</p>');
        $this->build();
        $html = $this->readOutput('elsewhere/index.html');
        self::assertStringContainsString('src="/assets/root.svg"', $html);
        self::assertStringContainsString('src="/_includes/local.svg"', $html);
        self::assertStringContainsString('src="/_includes/components/panel.svg"', $html);
        self::assertStringContainsString('<article><section>', $html);
        self::assertStringContainsString('<strong>Nested</strong>', $html);
        self::assertSame('<p>Plain</p>', $this->readOutput('plain/index.html'));
    }

    public function testMergeCollectionsDateSortingAndNonHtmlOutputs(): void
    {
        $this->write('site/_data/@navigation.json', '[{"title":"Home"}]');
        $this->write('site/@data.php', '<?php return ["settings" => ["a" => 1,"b" => 2],"nullable" => null,"tags" => ["parent"]];');
        $this->write('site/blog/@data.json', '{"settings":{"b":3},"tags":["posts"]}');
        $this->write('site/blog/@old.data.php', '<?php return ["title" => "Sidecar", "settings" => ["a" => 4]];');
        $this->write('site/blog/old.md', "---\ntitle: Older\ndate: \"2026-01-01\"\n---\n\nOld body");
        $this->write('site/blog/new.blade.php', '@meta(["title" => "Newer", "date" => "2026-10-10T09:00:00+09:00", "draft" => true])<h1>{{ Hanji::meta("title") }}</h1>');
        $this->write('site/blog/@new.data.php', '<?php throw new Exception("Blade sidecars must not execute");');
        $this->write('site/index.php', <<<'PHP'
            <?php
            Hanji::meta(['tags' => [], 'settings' => [], 'title' => 'Root']);
            Hanji::extension('json');
            echo json_encode([
                'posts' => array_map(fn ($post) => ['title' => $post->data['title'], 'settings' => $post->data['settings'], 'url' => $post->url, 'date' => $post->date->format('c')], $collections['posts']),
                'tags' => Hanji::meta('tags'), 'empty' => Hanji::meta('settings'), 'nullable' => Hanji::meta('nullable', 'fallback'),
                'title' => Hanji::meta('title'), 'navigation' => Hanji::meta('navigation'), 'count' => count($collections['all']),
            ], JSON_THROW_ON_ERROR);
            PHP);
        $this->build((new Config())->timezone('Asia/Seoul')->basePath('/docs'));
        $json = json_decode($this->readOutput('index.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('Root', $json['title']);
        self::assertSame([], $json['tags']);
        self::assertSame([], $json['empty']);
        self::assertNull($json['nullable']);
        self::assertSame(3, $json['count']);
        self::assertSame([['title' => 'Home']], $json['navigation']);
        self::assertIsArray($json['posts']);
        self::assertIsArray($json['posts'][0]);
        self::assertIsArray($json['posts'][1]);
        self::assertSame('Newer', $json['posts'][0]['title']);
        self::assertSame(['a' => 1, 'b' => 3], $json['posts'][0]['settings']);
        self::assertSame(['a' => 4, 'b' => 3], $json['posts'][1]['settings']);
        self::assertSame('/docs/blog/old/', $json['posts'][1]['url']);
        self::assertSame('2026-01-01T00:00:00+09:00', $json['posts'][1]['date']);
    }

    public function testAssetAttributesMarkdownReferencesAndStaticHtml(): void
    {
        $this->write('site/img/a b.svg', '<svg/>');
        $this->write('site/img/two.svg', '<svg/>');
        $this->write('site/.unreferenced', 'hidden');
        $this->write('site/_underscore', 'underscore');
        $this->write('site/@private.svg', 'secret');
        $this->write('site/index.blade.php', <<<'BLADE'
            @meta(['permalink' => '/moved/'])
            <img src="{{ './img/a%20b.svg' }}?x=1&amp;y=2#id" srcset="./img/a%20b.svg 1x, ./img/two.svg 2x">
            <source srcset="data:image/png;base64,AA== 1x, ./img/two.svg 2x">
            <video poster="./img/two.svg" src="https://example.com/movie.mp4"></video>
            <link rel="icon" href=./img/two.svg>
            <link rel="{{ 'stylesheet' }}" href="./img/two.svg">
            <a href="./does-not-exist">link unaffected</a>
            <script>const sample = '<img src="./not-real">';</script>
            @verbatim <p>{{ '<img src="./not-real">' }}</p> @endverbatim
            BLADE);
        $this->write('site/photo.md', "![image][photo]\n\n[photo]: ./img/a%20b.svg\n\n`![code](./not-real.svg)`\n\n<img src=\"./not-real\">\n");
        $this->write('site/static.html', '<img src="./img/two.svg"><img src="/root.svg"><img src="//example.org/a.svg"><img src="#id">');
        $this->build((new Config())->copy('img', 'public'));
        $html = $this->readOutput('moved/index.html');
        self::assertStringContainsString('/public/a%20b.svg?x=1&amp;y=2#id', $html);
        self::assertStringContainsString('srcset="/public/a%20b.svg 1x, /public/two.svg 2x"', $html);
        self::assertStringContainsString('data:image/png;base64,AA== 1x, /public/two.svg 2x', $html);
        self::assertStringContainsString('href="/public/two.svg"', $html);
        self::assertStringContainsString('src="./not-real"', $html);
        self::assertStringContainsString('src="/public/a%20b.svg"', $this->readOutput('photo/index.html'));
        self::assertStringContainsString('&lt;img', $this->readOutput('photo/index.html'));
        self::assertSame('<img src="/public/two.svg"><img src="/root.svg"><img src="//example.org/a.svg"><img src="#id">', $this->readOutput('static.html'));
        self::assertFileExists($this->root . '/_site/.unreferenced');
        self::assertFileExists($this->root . '/_site/_underscore');
        self::assertFileDoesNotExist($this->root . '/_site/@private.svg');
    }

    public function testCompiledTemplateReferencesResolveWithCurrentParams(): void
    {
        $this->write('site/[name]/@params.php', '<?php return ["alpha", "beta"];');
        $this->write('site/[name]/data.php', '<?php Hanji::extension("json"); echo "{}";');
        $this->write('site/[name]/index.blade.php', '<script src="./data.php"></script>');
        $this->build();
        self::assertSame('<script src="/alpha/data.json"></script>', $this->readOutput('alpha/index.html'));
        self::assertSame('<script src="/beta/data.json"></script>', $this->readOutput('beta/index.html'));
    }

    public function testPhpDeclarationSharesBlockWithBodyAndPreservesOriginalPaths(): void
    {
        $this->write('site/cover.svg', '<svg/>');
        $this->write('site/index.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            Hanji::meta(['title' => 'PHP']);
            echo '<h1>' . Hanji::meta('title') . '</h1><img src="./cover.svg">';
            ?>
            <p><?= basename(__FILE__) ?> <?= basename(__DIR__) ?></p>
            PHP);
        $this->write('site/alias.blade.php', <<<'BLADE'
            <?php use Hanji as H; ?>
            @php H::meta(['title' => 'Alias']); @endphp
            <h1>{{ H::meta('title') }}</h1>
            BLADE);
        $this->write('site/native.blade.php', '<?php Hanji::meta(["title" => "Native"]); echo Hanji::meta("title"); ?><p>Body</p>');
        $this->write('site/mixed.blade.php', '@meta(["title" => "Mixed"])<?php echo Hanji::meta("title");');
        $this->build();
        self::assertStringContainsString('<h1>PHP</h1><img src="/cover.svg">', $this->readOutput('index.html'));
        self::assertStringContainsString('<p>index.php site</p>', $this->readOutput('index.html'));
        self::assertSame('<h1>Alias</h1>', $this->readOutput('alias/index.html'));
        self::assertSame('Native<p>Body</p>', $this->readOutput('native/index.html'));
        self::assertSame('Mixed', $this->readOutput('mixed/index.html'));
    }

    public function testDeclarationTokensIgnoreStringsCommentsEscapesAndVerbatim(): void
    {
        $this->write('site/index.blade.php', <<<'BLADE'
            {{-- @meta(['title' => 'ignored']) --}}
            @php
            // Hanji::meta(['title' => 'ignored']); @endphp
            $text = 'Literal @endphp and Hanji::meta(["ignored"])';
            Hanji::meta(['title' => $text]);
            @endphp
            <h1>{{ Hanji::meta('title') }}</h1>
            @@meta(['title' => 'visible'])
            @verbatim{{ Hanji::meta(['title' => 'visible']) }}@endverbatim
            BLADE);
        $this->build();
        $html = $this->readOutput('index.html');
        self::assertStringContainsString('Literal @endphp and Hanji::meta', $html);
        self::assertStringContainsString('@meta([\'title\' => \'visible\'])', $html);
        self::assertStringContainsString('{{ Hanji::meta([\'title\' => \'visible\']) }}', $html);
    }

    public function testNonHtmlBladeBytesAndRawMarkdownOption(): void
    {
        $this->write('site/bytes.blade.php', "@php Hanji::extension('txt'); @endphp\n{{ \"  leading\\ntrailing  \\n\" }}");
        $this->write('site/native.php', '<?php Hanji::extension("txt"); echo "  leading\\ntrailing  \\n";');
        $this->write('site/page.md', '<img src="./cover.svg">');
        $this->write('site/cover.svg', '<svg/>');
        $this->build((new Config())->markdownHtml(true));
        self::assertSame("  leading\ntrailing  \n", $this->readOutput('bytes.txt'));
        self::assertSame("  leading\ntrailing  \n", $this->readOutput('native.txt'));
        self::assertStringContainsString('src="/cover.svg"', $this->readOutput('page/index.html'));
    }

    public function testInterruptedPublishRestoresBackupAndCanBuildAgain(): void
    {
        $this->write('site/index.php', '<?php echo "before";');
        $this->build();
        $settings = (new Config())->resolve($this->root);
        $hash = hash('sha256', $settings->output);
        $prefix = dirname($settings->output) . '/.hanji-build-' . substr($hash, 0, 12) . '-';
        $backup = $prefix . 'interrupted-backup';
        $stage = $prefix . 'interrupted-stage';
        $this->files->rename($settings->output, $backup);
        $this->files->dumpFile($settings->output . '/index.html', 'incomplete');
        $this->write('.hanji/state/' . $hash . '.journal.json', json_encode(['backup' => $backup, 'stage' => $stage, 'previous' => true, 'generation' => 'interrupted'], JSON_THROW_ON_ERROR));
        $publisher = new Publisher($settings);
        $publisher->close();
        self::assertSame('before', $this->readOutput('index.html'));
        self::assertDirectoryDoesNotExist($backup);
        $this->write('site/index.php', '<?php echo "after";');
        $this->build();
        self::assertSame('after', $this->readOutput('index.html'));
    }

    public function testBladeLoopPropsSlotsAndSectionIsolation(): void
    {
        $this->write('site/_includes/@frame.blade.php', '<main>@yield("body", "fallback")@stack("scripts")</main>');
        $this->write('site/_includes/components/@box.blade.php', '@props(["kind" => "paper"])<aside data-kind="{{ $kind }}" {{ $attributes }}>{{ $heading ?? "" }}{{ $slot }}</aside>');
        $this->write('site/index.blade.php', <<<'BLADE'
            @extends('frame')
            @section('body')
            @foreach ([1, 2] as $number)<b>{{ $loop->iteration }}:{{ $number }}</b>@endforeach
            <x-box kind="hanji" data-test="card"><x-slot:heading><h1>Heading</h1></x-slot:heading>Body</x-box>
            @endsection
            @push('scripts')<script>first</script>@endpush
            BLADE);
        $this->write('site/two.blade.php', '@extends("frame")@section("body")<p>Two</p>@endsection');
        $this->build();
        $first = $this->readOutput('index.html');
        self::assertStringContainsString('<b>1:1</b><b>2:2</b>', $first);
        self::assertStringContainsString('data-kind="hanji"', $first);
        self::assertStringContainsString('data-test="card"', $first);
        self::assertStringContainsString('<h1>Heading</h1>Body', $first);
        self::assertStringContainsString('<script>first</script>', $first);
        self::assertSame('<main><p>Two</p></main>', $this->readOutput('two/index.html'));
    }

    public function testScssImportsModesAndRebuildDeletesStaleFiles(): void
    {
        $this->write('site/styles/@tokens.scss', '$color: #123456;');
        $this->write('site/styles/app.scss', '@import "./@tokens.scss"; .paper { color: $color; &:hover { color: white; } }');
        $this->write('site/old.php', '<?php echo "old";');
        $config = (new Config())->scss('styles/app.scss', 'css/main.css');
        $this->build($config, 'dev');
        self::assertFileExists($this->root . '/_site/css/main.css.map');
        self::assertStringContainsString('.paper:hover', $this->readOutput('css/main.css'));
        self::assertStringContainsString('main.css.map', $this->readOutput('css/main.css'));
        self::assertStringNotContainsString($this->root, $this->readOutput('css/main.css.map'));
        $this->files->remove($this->root . '/site/old.php');
        $this->write('site/new.php', '<?php echo "new";');
        $this->build($config);
        self::assertFileDoesNotExist($this->root . '/_site/old/index.html');
        self::assertFileDoesNotExist($this->root . '/_site/css/main.css.map');
        self::assertSame('new', $this->readOutput('new/index.html'));
        self::assertStringNotContainsString("\n  ", $this->readOutput('css/main.css'));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidBuilds(): iterable
    {
        yield 'late metadata' => [['index.php' => '<?php echo "body"; Hanji::meta(["title" => "late"]);'], 'leading declaration'];
        yield 'duplicate metadata' => [['index.blade.php' => '@meta(["title" => "one"]) @meta(["title" => "two"])Body'], 'once'];
        yield 'late extension' => [['index.php' => '<?php echo "body"; Hanji::extension("json");'], 'leading declaration'];
        yield 'collections in declaration' => [['index.blade.php' => '@php Hanji::meta(["title" => $collections["posts"][0]->data["title"]]); @endphp Body'], 'unavailable while collecting declarations'];
        yield 'children in body' => [['index.blade.php' => '{{ Hanji::children() }}'], 'only while rendering a layout'];
        yield 'declaration emits output' => [['index.blade.php' => '@php echo "bad"; Hanji::meta(["title" => "bad"]); @endphp Body'], 'must not emit output'];
        yield 'unbalanced PHP buffer' => [['index.php' => '<?php ob_end_clean();'], 'buffer was closed'];
        yield 'layout declaration' => [['index.blade.php' => 'Body', '@layout.blade.php' => '@meta(["title" => "bad"]){{ Hanji::children() }}'], 'leading declaration'];
        yield 'include declaration' => [['index.blade.php' => '@include("header")', '_includes/@header.blade.php' => '@meta(["title" => "bad"])Header'], 'leading declaration'];
        yield 'invalid extension' => [['index.php' => '<?php Hanji::extension("php"); echo "bad";'], 'invalid output extension'];
        yield 'output collision' => [['about.php' => '<?php echo "a";', 'about/index.blade.php' => 'b'], 'collision'];
        yield 'case collision' => [['a.php' => '<?php echo "a";', 'A.blade.php' => 'b'], 'collision'];
        yield 'file directory collision' => [['a' => 'a', 'a.php' => '<?php echo "b";'], 'collision'];
        yield 'missing asset' => [['index.blade.php' => '<img src="./missing.svg">'], 'Missing asset'];
        yield 'encoded separator' => [['index.blade.php' => '<img src=".%2fmissing.svg">'], 'Invalid relative asset'];
        yield 'private asset' => [['index.blade.php' => '<img src="./@private.svg">', '@private.svg' => 'x'], 'Special files'];
        yield 'invalid date' => [['index.blade.php' => '@meta(["date" => "2026-02-30"])Bad'], 'Invalid date'];
        yield 'reserved tag' => [['index.blade.php' => '@meta(["tags" => ["all"]])Bad'], 'reserved'];
        yield 'invalid layout' => [['index.blade.php' => '@meta(["layout" => "none"])Bad'], 'boolean'];
        yield 'invalid title' => [['index.blade.php' => '@meta(["title" => 3])Bad'], 'title must be a string'];
        yield 'non html directory' => [['index.php' => '<?php Hanji::extension("json"); Hanji::meta(["permalink" => "/feed/"]); echo "{}";'], 'file permalink'];
        yield 'invalid permalink' => [['index.blade.php' => '@meta(["permalink" => "/../escape/"])Bad'], 'Invalid path segment'];
        yield 'missing provider' => [['[name]/index.php' => '<?php echo "x";'], 'Missing parameter provider'];
        yield 'bad parameter' => [['[name]/@params.php' => '<?php return ["../escape"];', '[name]/index.php' => '<?php echo "x";'], 'Invalid dynamic'];
        yield 'duplicate parameter' => [['[name]/@params.php' => '<?php return ["One","one"];', '[name]/index.php' => '<?php echo "x";'], 'duplicate parameter'];
        yield 'mixed parameter formats' => [['[name]/@params.php' => '<?php return ["one",["value" => "two"]];', '[name]/index.php' => '<?php echo "x";'], 'consistent row format'];
        yield 'parameter provider output' => [['[name]/@params.php' => '<?php echo "bad"; return ["one"];'], 'without output'];
        yield 'static parameter provider' => [['@params.php' => '<?php return ["one"];'], 'requires a dynamic directory'];
        yield 'reused parameter name' => [['[name]/@params.php' => '<?php return ["one"];', '[name]/[name]/@params.php' => '<?php return ["two"];'], 'duplicate dynamic parameter'];
        yield 'ambiguous dynamic reference' => [['index.blade.php' => '<script src="./[name]/data.php"></script>', '[name]/@params.php' => '<?php return ["one","two"];', '[name]/data.php' => '<?php Hanji::extension("json"); echo "{}";'], 'Ambiguous dynamic asset'];
        yield 'duplicate data' => [['@data.php' => '<?php return [];', '@data.json' => '{}'], 'Duplicate data'];
        yield 'bad scss' => [['app.scss' => 'a { color: $undefined; }'], 'Undefined variable'];
        yield 'modules unsupported' => [['app.scss' => '@use "sass:color";'], '@use'];
        yield 'forward unsupported' => [['app.scss' => '@forward "other";'], '@forward'];
    }

    /** @param array<string, string> $sources */
    #[DataProvider('invalidBuilds')]
    public function testFailuresPreservePreviousOutputAndClearContext(array $sources, string $message): void
    {
        $this->write('site/index.php', '<?php echo "original";');
        $this->build();
        $this->files->remove($this->root . '/site');
        foreach ($sources as $relative => $content) {
            $this->write('site/' . $relative, $content);
        }
        try {
            $this->build();
            self::fail('Build should fail.');
        } catch (BuildException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame('original', $this->readOutput('index.html'));
        self::assertSame([], glob($this->root . '/.hanji-build-*'));
        $this->expectException(BuildException::class);
        \Hanji::meta();
    }

    public function testCleanOwnershipAndConcurrentBuildLock(): void
    {
        $this->write('_site/user.txt', 'keep');
        try {
            $this->build();
            self::fail('Unowned output must not be replaced.');
        } catch (BuildException $error) {
            self::assertStringContainsString('not owned', $error->getMessage());
        }
        self::assertSame('keep', $this->readOutput('user.txt'));
        $this->files->remove($this->root . '/_site');
        $this->write('site/index.php', '<?php echo "hello";');
        $this->build();
        $settings = (new Config())->resolve($this->root);
        $publisher = new Publisher($settings);
        try {
            try {
                $this->build();
                self::fail('Concurrent build must fail.');
            } catch (BuildException $error) {
                self::assertStringContainsString('already running', $error->getMessage());
            }
            $publisher->clean();
        } finally {
            $publisher->close();
        }
        self::assertDirectoryDoesNotExist($settings->output);
        self::assertDirectoryDoesNotExist($settings->cache);
        self::assertFileExists($settings->input . '/index.php');
    }

    public function testConfigObjectReloadAndValidation(): void
    {
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->title("One");');
        self::assertSame('One', (new ConfigLoader())->load($this->root)->site['title']);
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->title("Two");');
        self::assertSame('Two', (new ConfigLoader())->load($this->root)->site['title']);
        $this->write('hanji.php', '<?php return fn () => new Hanji\\Config();');
        $this->expectException(ConfigurationException::class);
        (new ConfigLoader())->load($this->root);
    }

    public function testPathValidationDoesNotTouchExistingInputOrForeignCache(): void
    {
        $this->write('site/keep.txt', 'keep');
        foreach ([(new Config())->output('site/out'), (new Config())->cache('site/cache'), (new Config())->output('.hanji/out'), (new Config())->cache('.hanji'), (new Config())->output('../outside')] as $config) {
            try {
                $config->resolve($this->root);
                self::fail('Overlapping or outside paths must be rejected.');
            } catch (ConfigurationException) {
                self::assertSame('keep', file_get_contents($this->root . '/site/keep.txt'));
            }
        }
        $this->write('.foreign/user.txt', 'keep');
        try {
            $this->build((new Config())->cache('.foreign'));
            self::fail('Foreign cache must be rejected.');
        } catch (BuildException $error) {
            self::assertStringContainsString('not owned', $error->getMessage());
        }
        self::assertSame('keep', file_get_contents($this->root . '/.foreign/user.txt'));
    }

    public function testInitBuildAndCliExitCodes(): void
    {
        (new Initializer())->initialize($this->root);
        self::assertFileDoesNotExist($this->root . '/README.md');
        $application = Console::application();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => 'build', '--config' => $this->root . '/hanji.php']));
        self::assertStringContainsString('Built 1 pages and 1 assets', $tester->getDisplay());
        self::assertSame(2, $tester->run(['command' => 'build', '--config' => $this->root . '/missing.php']));
        $this->write('site/index.blade.php', '<img src="./missing.svg">');
        self::assertSame(1, $tester->run(['command' => 'build', '--config' => $this->root . '/hanji.php']));
        $original = file_get_contents($this->root . '/hanji.php');
        try {
            (new Initializer())->initialize($this->root);
            self::fail('Init must not overwrite.');
        } catch (ConfigurationException) {
            self::assertSame($original, file_get_contents($this->root . '/hanji.php'));
        }
    }

    private function write(string $path, string $content): void
    {
        $this->files->dumpFile($this->root . '/' . $path, $content);
    }

    private function readOutput(string $path): string
    {
        $content = file_get_contents($this->root . '/_site/' . $path);
        self::assertIsString($content);
        return $content;
    }

    /** @return list<string> */
    private function outputs(): array
    {
        $paths = [];
        foreach (Finder::create()->files()->in($this->root . '/_site')->ignoreDotFiles(false)->sortByName() as $file) {
            $paths[] = $file->getRelativePathname();
        }
        return $paths;
    }

    private function build(?Config $config = null, string $mode = 'prod'): \Hanji\BuildResult
    {
        return (new Builder())->build(($config ?? new Config())->resolve($this->root), $mode);
    }
}
