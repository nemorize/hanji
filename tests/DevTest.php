<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Builder;
use Hanji\Config;
use Hanji\Console\Reporter;
use Hanji\Dev\LiveState;
use Hanji\Dev\Project;
use Hanji\Dev\Protocol;
use Hanji\Dev\Router;
use Hanji\Dev\Runner;
use Hanji\Dev\Snapshot;
use Hanji\Diagnostic;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

/** @phpstan-type PreviewState array{revision: int, status: string, error: array{message: string, source: ?string}|null, changes: list<array{type: string, paths: list<string>}>, basePath: string} */
final class DevTest extends TestCase
{
    private string $root;
    private Filesystem $files;
    private ?Runner $runner = null;
    private string $origin = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/hanji-dev-test-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->mkdir($this->root . '/site');
        $this->write('hanji.php', '<?php return new Hanji\\Config();');
        $this->write('site/index.php', '<!doctype html><html><head><link rel="stylesheet" href="./style.css"></head><body>Original<input id="state"></body></html>');
        $this->write('site/style.css', 'body { color: red; }');
    }

    protected function tearDown(): void
    {
        $this->runner?->close();
        $this->files->remove([$this->root, $this->root . '-rendering']);
    }

    public function testServerServesPagesAssetsAndHeadWithoutChangingPublishedHtml(): void
    {
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->basePath("/docs/");');
        $this->write('site/products/[name]/@params.php', '<?php return ["one", "two"];');
        $this->write('site/products/[name]/index.php', '<img src="./cover.svg">');
        $this->write('site/products/[name]/cover.svg', '<svg/>');
        $this->write('site/image.png', "\0\xffPNG");
        $this->write('site/feed.php', '<?php Hanji::extension("json"); echo "{}";');
        $this->start();
        self::assertStringContainsString('http://127.0.0.1:', $this->runner?->url() ?? '');
        self::assertStringEndsWith('/docs/', $this->runner?->url() ?? '');
        $html = $this->get('/docs/');
        self::assertStringContainsString('Original', $html);
        self::assertStringContainsString('defer src="/__hanji/client.js"', $html);
        self::assertStringContainsString('data-base-path="/docs/"', $html);
        self::assertStringNotContainsString('__hanji', $this->read('index.html'));
        self::assertStringContainsString('src="/docs/products/%5Bname%5D/cover.svg"', $this->get('/docs/products/one/'));
        self::assertSame('<svg/>', $this->get('/docs/products/%5Bname%5D/cover.svg'));
        self::assertSame("\0\xffPNG", $this->get('/docs/image.png'));
        self::assertSame('{}', $this->get('/docs/feed.json'));
        self::assertSame('', $this->get('/docs/', 'HEAD'));
        self::assertStringContainsString('no-store', implode("\n", $this->headers('/docs/style.css')));
        self::assertStringContainsString('text/css', implode("\n", $this->headers('/docs/style.css')));
        self::assertStringNotContainsString($this->root, $this->get('/__hanji/status'));
    }

    public function testStylesheetChangesEmitCssUpdatesAndPageChangesEmitReloads(): void
    {
        $this->start();
        $html = $this->read('index.html');
        $this->write('site/style.css', 'body { color: blue; }');
        $state = $this->waitFor(static fn(array $state): bool => $state['revision'] === 2 && $state['status'] === 'ready');
        self::assertSame('css', $state['changes'][1]['type']);
        self::assertSame(['/style.css'], $state['changes'][1]['paths']);
        self::assertSame($html, $this->read('index.html'));
        self::assertStringContainsString('blue', $this->get('/style.css'));
        $this->write('site/index.php', '<h1>Updated</h1>');
        $state = $this->waitFor(static fn(array $state): bool => $state['revision'] === 3 && $state['status'] === 'ready');
        self::assertSame('reload', $state['changes'][2]['type']);
        self::assertStringContainsString('Updated', $this->get('/'));
    }

    public function testScssPartialChangesEmitCompiledCssUpdates(): void
    {
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->plugin(new Hanji\\Plugin\\Build\\ScssBuildPlugin());');
        $this->write('site/index.php', '<link rel="stylesheet" href="./app.scss">');
        $this->write('site/@tokens.scss', '$color: red;');
        $this->write('site/app.scss', '@import "./@tokens.scss"; body { color: $color; }');
        $this->start();
        self::assertStringContainsString('href="/app.css"', $this->get('/'));
        $this->write('site/@tokens.scss', '$color: blue;');
        $state = $this->waitFor(static fn(array $state): bool => $state['revision'] === 2 && $state['status'] === 'ready');
        self::assertSame('css', $state['changes'][1]['type']);
        self::assertSame(['/app.css'], $state['changes'][1]['paths']);
        self::assertStringContainsString('blue', $this->get('/app.css'));
        self::assertFileDoesNotExist($this->root . '/_site/@tokens.scss');
    }

    public function testInitialBuildErrorCanRecoverAndServePreviouslyOwnedOutput(): void
    {
        (new Builder())->build((new Config())->resolve($this->root));
        $this->write('site/index.php', '<img src="./missing.svg">');
        $this->start();
        self::assertSame('error', $this->state()['status']);
        self::assertStringContainsString('Original', $this->get('/'));
        $this->write('site/missing.svg', '<svg/>');
        $this->waitFor(static fn(array $state): bool => $state['revision'] === 1 && $state['status'] === 'ready');
        self::assertStringContainsString('src="/missing.svg"', $this->get('/'));
    }

    public function testConfigCanSwitchInputAndOutputWithoutWatchingGeneratedFiles(): void
    {
        $this->start();
        $this->write('content/index.php', 'New input');
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->input("content")->output("dist")->cache(".hanji/alternate-cache");');
        $state = $this->waitFor(static fn(array $state): bool => $state['revision'] >= 2 && $state['status'] === 'ready');
        self::assertSame('reload', $state['changes'][count($state['changes']) - 1]['type']);
        self::assertStringContainsString('New input', $this->get('/'));
        $this->write('content/index.php', 'Changed input');
        $this->waitFor(static fn(array $next): bool => $next['revision'] > $state['revision'] && $next['status'] === 'ready');
        self::assertStringContainsString('Changed input', $this->get('/'));
    }

    public function testFailedBuildKeepsTheLastOutputAndRecoversWithoutRestart(): void
    {
        $this->start();
        $original = $this->read('index.html');
        $this->write('site/index.php', '<img src="./missing.svg">');
        $state = $this->waitFor(static fn(array $state): bool => $state['status'] === 'error');
        self::assertSame(1, $state['revision']);
        self::assertNotNull($state['error']);
        self::assertSame('Missing asset ./missing.svg', $state['error']['message']);
        self::assertSame('site/index.php', $state['error']['source']);
        self::assertSame($original, $this->read('index.html'));
        self::assertStringContainsString('Original', $this->get('/'));
        $this->write('site/missing.svg', '<svg/>');
        $state = $this->waitFor(static fn(array $state): bool => $state['status'] === 'ready' && $state['revision'] === 2);
        self::assertNull($state['error']);
        self::assertStringContainsString('src="/missing.svg"', $this->get('/'));
    }

    public function testInputLayoutParamsAndPageDeletionTriggerRebuilds(): void
    {
        $this->write('site/@layout.blade.php', '<main>{{ Hanji::children() }}</main>');
        $this->write('site/[name]/@params.php', '<?php return ["one"];');
        $this->write('site/[name]/index.php', 'Dynamic');
        $this->start();
        self::assertStringContainsString('Dynamic', $this->get('/one/'));
        $this->write('site/[name]/@params.php', '<?php return ["two"];');
        $this->write('site/@layout.blade.php', '<article>{{ Hanji::children() }}</article>');
        $state = $this->waitFor(static fn(array $state): bool => $state['revision'] >= 2 && $state['status'] === 'ready');
        self::assertStringContainsString('<article>Dynamic</article>', $this->get('/two/'));
        self::assertFileDoesNotExist($this->root . '/_site/one/index.html');
        $this->files->remove($this->root . '/site/[name]/index.php');
        $this->waitFor(static fn(array $next): bool => $next['revision'] > $state['revision'] && $next['status'] === 'ready');
        self::assertFileDoesNotExist($this->root . '/_site/two/index.html');
    }

    public function testNewWorkersReloadConfigAndPhpClassesWithoutRedeclarationErrors(): void
    {
        $this->write('site/@helper.php', '<?php class Greeting { public static function text(): string { return "First"; } }');
        $this->write('site/index.php', '<?php require __DIR__ . "/@helper.php"; echo Greeting::text();');
        $this->start();
        self::assertStringContainsString('First', $this->get('/'));
        $this->write('site/@helper.php', '<?php class Greeting { public static function text(): string { return "Second"; } }');
        $this->waitFor(static fn(array $state): bool => $state['revision'] === 2 && $state['status'] === 'ready');
        self::assertStringContainsString('Second', $this->get('/'));
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->basePath("/new/");');
        $state = $this->waitFor(static fn(array $state): bool => $state['revision'] === 3 && $state['status'] === 'ready');
        self::assertSame('/new/', $state['basePath']);
        self::assertStringContainsString('Second', $this->get('/new/'));
        $this->write('hanji.php', '<?php this is not valid PHP');
        $this->waitFor(static fn(array $state): bool => $state['status'] === 'error');
        self::assertStringContainsString('Second', $this->get('/new/'));
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->basePath("/new/");');
        $this->waitFor(static fn(array $state): bool => $state['revision'] === 4 && $state['status'] === 'ready');
    }

    public function testEditsDuringABuildAreQueuedForAnotherBuild(): void
    {
        $this->start();
        $marker = var_export($this->root . '-rendering', true);
        $this->write('site/index.php', '<?php file_put_contents(' . $marker . ', "started"); usleep(400000); echo "First change";');
        $this->waitFor(fn(array $state): bool => $state['status'] === 'building' && is_file($this->root . '-rendering'));
        self::assertStringContainsString('Original', $this->get('/'));
        $this->write('site/index.php', '<h1>Second change</h1>');
        $this->waitFor(static fn(array $state): bool => $state['revision'] >= 3 && $state['status'] === 'ready');
        self::assertStringContainsString('Second change', $this->get('/'));
    }

    public function testOutputCacheAndStateChangesDoNotCreateRebuildLoops(): void
    {
        $this->start();
        $this->write('_site/extra.txt', 'generated');
        $this->write('.hanji/cache/extra.txt', 'cache');
        $until = microtime(true) + 0.65;
        do {
            $this->runner?->tick();
            usleep(20000);
        } while (microtime(true) < $until);
        self::assertSame(1, $this->state()['revision']);
    }

    public function testUnownedOutputIsNeverServedWhenInitialBuildFails(): void
    {
        $this->write('_site/index.html', 'Private foreign output');
        $this->start();
        self::assertSame('error', $this->state()['status']);
        self::assertStringNotContainsString('Private foreign output', $this->get('/'));
        self::assertSame('Private foreign output', $this->read('index.html'));
    }

    public function testCopiedPhpIsServedAsBytesAndNeverExecuted(): void
    {
        $this->write('hanji.php', '<?php return (new Hanji\\Config())->plugins(new Hanji\\Plugin\\Build\\BladeBuildPlugin(), new Hanji\\Plugin\\Transform\\RelativePathTransformPlugin());');
        $php = '<?php file_put_contents(' . var_export($this->root . '/executed', true) . ', "bad"); echo "executed";';
        $this->write('site/raw.php', $php);
        $this->start();
        self::assertSame($php, $this->get('/raw.php'));
        self::assertFileDoesNotExist($this->root . '/executed');
    }

    public function testSnapshotDetectsSameSizeChangesAndIgnoresGeneratedTrees(): void
    {
        $project = Project::from((new Config())->resolve($this->root));
        $before = Snapshot::sources($project, $this->root . '/hanji.php');
        $time = filemtime($this->root . '/site/style.css');
        $this->write('site/style.css', 'body { color: tan; }');
        if ($time !== false) {
            touch($this->root . '/site/style.css', $time);
        }
        $this->write('_site/index.html', 'generated');
        $this->write('.hanji/cache/compiled.php', 'generated');
        self::assertSame([$this->root . '/site/style.css'], Snapshot::changes($before, Snapshot::sources($project, $this->root . '/hanji.php')));
    }

    public function testRouterRejectsTraversalAndWrongHostsAndReturnsReal404s(): void
    {
        (new Builder())->build((new Config())->resolve($this->root));
        $project = Project::from((new Config())->resolve($this->root));
        $state = new LiveState($project, $this->root . '/state.json', '127.0.0.1');
        $state->ready($project, [], Snapshot::tree($project->output));
        $router = new Router(Protocol::read($this->root . '/state.json'));
        foreach (['/../hanji.php', '/%2e%2e/hanji.php', '/a%2fb', '/a%5cb', '/%00', '/bad%xx'] as $path) {
            self::assertSame(400, $router->handle($path)->status);
        }
        self::assertSame(403, $router->handle('/', host: 'malicious.example')->status);
        self::assertSame(405, $router->handle('/', 'POST')->status);
        self::assertSame(404, $router->handle('/missing/')->status);
        self::assertStringNotContainsString('Original', $router->handle('/missing/')->body);
        self::assertSame(200, $router->handle('/')->status);
        if (@symlink($this->root . '/hanji.php', $project->output . '/leak.txt')) {
            self::assertSame(404, $router->handle('/leak.txt')->status);
        }
    }

    public function testRouterRedirectsBasePathAndDirectoriesWithQueryStrings(): void
    {
        $this->write('site/posts/index.php', 'Posts');
        $settings = (new Config())->basePath('/docs/')->resolve($this->root);
        (new Builder())->build($settings);
        $project = Project::from($settings);
        $state = new LiveState($project, $this->root . '/state.json', '127.0.0.1');
        $state->ready($project, [], Snapshot::tree($project->output));
        $router = new Router(Protocol::read($this->root . '/state.json'));
        self::assertSame('/docs/?preview=1', $router->handle('/?preview=1')->headers['Location']);
        self::assertSame('/docs/?preview=1', $router->handle('/docs?preview=1')->headers['Location']);
        self::assertSame('/docs/posts/?preview=1', $router->handle('/docs/posts?preview=1')->headers['Location']);
        self::assertSame(200, $router->handle('/docs/posts/')->status);
    }

    public function testLiveStateKeepsHistoryAndMakesCssDeletionsFullReloads(): void
    {
        $project = Project::from((new Config())->resolve($this->root));
        $path = $this->root . '/state.json';
        $state = new LiveState($project, $path, '127.0.0.1');
        $css = $project->output . '/style.css';
        $state->ready($project, [], [$css => 'one']);
        $state->ready($project, [$css => 'one'], [$css => 'two']);
        $state->ready($project, [$css => 'two'], [$css => 'three']);
        $data = Protocol::read($path);
        self::assertSame(3, $data['revision']);
        self::assertCount(3, is_array($data['changes']) ? $data['changes'] : []);
        $state->failed(new Diagnostic('Broken', $this->root . '/site/index.php'));
        self::assertSame(3, Protocol::read($path)['revision']);
        $state->ready($project, [$css => 'three'], []);
        $data = Protocol::read($path);
        self::assertNull($data['error']);
        self::assertSame('reload', is_array($data['changes']) && is_array($data['changes'][3]) ? $data['changes'][3]['type'] : null);
    }

    public function testCloseReleasesThePreviewPort(): void
    {
        $this->start();
        $port = parse_url($this->origin, PHP_URL_PORT);
        $this->runner?->close();
        self::assertFalse(@stream_socket_client('tcp://127.0.0.1:' . $port, $code, $error, 0.1));
    }

    private function start(): void
    {
        $this->runner = new Runner($this->root, $this->root . '/hanji.php', new Reporter(new BufferedOutput(), $this->root), port: 0);
        $this->runner->start();
        $url = $this->runner->url();
        $port = parse_url($url, PHP_URL_PORT);
        self::assertIsInt($port);
        $this->origin = 'http://127.0.0.1:' . $port;
    }

    private function get(string $path, string $method = 'GET'): string
    {
        $content = file_get_contents($this->origin . $path, false, stream_context_create(['http' => ['method' => $method, 'timeout' => 2, 'ignore_errors' => true]]));
        self::assertIsString($content);
        return $content;
    }

    /** @return list<string> */
    private function headers(string $path): array
    {
        $headers = get_headers($this->origin . $path);
        self::assertIsArray($headers);
        return $headers;
    }

    /** @return PreviewState */
    private function state(): array
    {
        $value = json_decode($this->get('/__hanji/status'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertIsInt($value['revision']);
        self::assertIsString($value['status']);
        self::assertIsString($value['basePath']);
        $error = $value['error'];
        $parsedError = null;
        if ($error !== null) {
            self::assertIsArray($error);
            self::assertIsString($error['message']);
            $source = $error['source'];
            if ($source !== null) {
                self::assertIsString($source);
            }
            $parsedError = ['message' => $error['message'], 'source' => $source];
        }
        $changes = $value['changes'];
        self::assertIsArray($changes);
        self::assertTrue(array_is_list($changes));
        $parsedChanges = [];
        foreach ($changes as $change) {
            self::assertIsArray($change);
            self::assertIsString($change['type']);
            self::assertIsArray($change['paths']);
            self::assertTrue(array_is_list($change['paths']));
            $paths = [];
            foreach ($change['paths'] as $path) {
                self::assertIsString($path);
                $paths[] = $path;
            }
            $parsedChanges[] = ['type' => $change['type'], 'paths' => $paths];
        }
        return ['revision' => $value['revision'], 'status' => $value['status'], 'error' => $parsedError, 'changes' => $parsedChanges, 'basePath' => $value['basePath']];
    }

    /**
     * @param callable(PreviewState): bool $ready
     * @return PreviewState
     */
    private function waitFor(callable $ready): array
    {
        $until = microtime(true) + 8;
        do {
            $this->runner?->tick();
            $state = $this->state();
            if ($ready($state)) {
                return $state;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        self::fail('Timed out waiting for development update: ' . json_encode($state));
    }

    private function write(string $path, string $content): void
    {
        $this->files->dumpFile($this->root . '/' . $path, $content);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($this->root . '/_site/' . $path);
        self::assertIsString($content);
        return $content;
    }
}
