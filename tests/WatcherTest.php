<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Config;
use Hanji\Dev\Project;
use Hanji\Dev\Watcher;
use Hanji\Dev\Watch\Scope;
use Hanji\Dev\Watch\Polling;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WatcherTest extends TestCase
{
    private string $root;
    private Filesystem $files;
    private ?Watcher $watcher = null;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/hanji-watch-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->write('site/style.css', 'red');
        $this->write('hanji.php', '<?php return new Hanji\\Config();');
    }

    protected function tearDown(): void
    {
        $this->watcher?->close();
        $this->files->remove($this->root);
    }

    public function testNativeWatcherDetectsSameSizeChangesCreatesRenamesAndDeletes(): void
    {
        $this->native();
        self::assertStringContainsString('native', $this->watcher?->name() ?? '');
        $time = filemtime($this->root . '/site/style.css');
        $this->write('site/style.css', 'tan');
        if ($time !== false) {
            touch($this->root . '/site/style.css', $time);
        }
        self::assertContains($this->root . '/site/style.css', $this->changes());
        $this->write('site/new/deep/page.php', 'Page');
        self::assertContains($this->root . '/site/new/deep/page.php', $this->changes());
        $this->files->rename($this->root . '/site/new/deep/page.php', $this->root . '/site/new/deep/renamed.php');
        $changes = $this->changes();
        self::assertContains($this->root . '/site/new/deep/page.php', $changes);
        self::assertContains($this->root . '/site/new/deep/renamed.php', $changes);
        $this->files->remove($this->root . '/site/new');
        self::assertContains($this->root . '/site/new/deep/renamed.php', $this->changes());
    }

    public function testNativeWatcherIgnoresOutputCacheAndProjectDependencies(): void
    {
        $this->native();
        foreach (['_site/page.html', '.hanji/cache/view.php', '.git/index', '.attic/test.txt', 'vendor/package/file.php', 'node_modules/file.js'] as $path) {
            $this->write($path, 'Ignored');
        }
        $until = microtime(true) + 0.5;
        do {
            self::assertSame([], $this->watcher?->changes());
            usleep(20000);
        } while (microtime(true) < $until);
        $this->write('site/@layout.blade.php', 'Layout');
        self::assertContains($this->root . '/site/@layout.blade.php', $this->changes());
    }

    public function testPollingFallbackSignalsRescans(): void
    {
        $project = Project::from((new Config())->resolve($this->root));
        $scope = new Scope($project, $this->root . '/hanji.php', [], []);
        $backend = new Polling($scope, 'native watcher unavailable');
        self::assertSame('polling · native watcher unavailable', $backend->name());
        self::assertSame([$this->root], $backend->events());
        self::assertSame([], $backend->events());
    }

    public function testReconfigurationKeepsEditsBeforeNativeEventsAreDelivered(): void
    {
        $this->native();
        $this->write('site/new.php', 'Created while compiling');
        $this->files->remove($this->root . '/site/style.css');
        $this->configure();
        $changes = $this->changes();
        self::assertContains($this->root . '/site/new.php', $changes);
        self::assertContains($this->root . '/site/style.css', $changes);
    }

    public function testScopeTraversesConfiguredInputAndKnownDependenciesWithinIgnoredDirectories(): void
    {
        $this->write('vendor/content/index.php', 'Input');
        $this->write('.attic/helper.php', '<?php function helper() {}');
        $project = Project::from((new Config())->input('vendor/content')->resolve($this->root));
        $scope = new Scope($project, $this->root . '/hanji.php', [$this->root . '/.attic/helper.php'], []);
        self::assertContains($project->input, $scope->directories());
        self::assertContains($this->root . '/.attic', $scope->directories());
        self::assertTrue($scope->includes($this->root . '/.attic/helper.php'));
        self::assertFalse($scope->includes($this->root . '/vendor/other/file.php'));
        self::assertFalse($scope->includes($this->root . '/.attic/other.php'));
    }

    private function native(): void
    {
        if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux', 'Windows'], true) || !class_exists(\FFI::class) || in_array(ini_get('ffi.enable'), ['', '0', false], true)) {
            self::markTestSkipped('Native file watching requires PHP FFI.');
        }
        $watcher = new Watcher();
        $this->watcher = $watcher;
        $this->configure();
        self::assertSame(match (PHP_OS_FAMILY) {
            'Darwin' => 'FSEvents · native',
            'Linux' => 'inotify · native',
            default => 'ReadDirectoryChangesW · native',
        }, $watcher->name());
    }

    private function configure(): void
    {
        $project = Project::from((new Config())->resolve($this->root));
        $this->watcher?->configure($project, $this->root . '/hanji.php', [], []);
    }

    /** @return list<string> */
    private function changes(): array
    {
        $until = microtime(true) + 4;
        do {
            $changes = $this->watcher?->changes() ?? [];
            if ($changes !== []) {
                return $changes;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        self::fail('Timed out waiting for a native filesystem event.');
    }

    private function write(string $path, string $content): void
    {
        $this->files->dumpFile($this->root . '/' . $path, $content);
    }
}
