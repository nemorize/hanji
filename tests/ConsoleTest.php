<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Builder;
use Hanji\Config;
use Hanji\Console;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;

final class ConsoleTest extends TestCase
{
    private string $root;
    private Filesystem $files;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/hanji-console-' . bin2hex(random_bytes(8));
        $this->files = new Filesystem();
        $this->files->mkdir($this->root . '/site');
        $this->write('hanji.php', '<?php return new Hanji\\Config();');
    }

    protected function tearDown(): void
    {
        $this->files->remove($this->root);
    }

    public function testBuildDisplaysStagesAndProjectRelativeSummary(): void
    {
        $this->write('site/index.php', 'Home');
        $this->write('site/app.css', 'body {}');
        $this->write('site/photo.png', "\0\xffPNG");
        $tester = $this->runCommand('build');
        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/HANJI  v0\.1\.0-dev  build\n\n  › scan     site\n  › prepare  pages · assets\n  › build    3 files\n  › publish  _site\n\n  ✓ Built 1 page and 2 assets in (?:\d+ms|\d+\.\d{2}s)\n    Output   _site\//', $tester->getDisplay(true));
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringNotContainsString($this->root, $tester->getDisplay());
        self::assertSame('Home', file_get_contents($this->root . '/_site/index.html'));
    }

    public function testVerboseBuildReportsEachDynamicOutputAndLiteralAssetOnce(): void
    {
        $this->write('site/[name]/@params.php', '<?php return ["one", "two"];');
        $this->write('site/[name]/index.blade.php', '<img src="./cover.svg">');
        $this->write('site/[name]/cover.svg', '<svg/>');
        $tester = $this->runCommand('build', ['--verbose' => true], decorated: true);
        self::assertSame(0, $tester->getStatusCode());
        $display = self::plain($tester->getDisplay(true));
        self::assertStringContainsString('build    3 files', $display);
        self::assertMatchesRegularExpression('/\[1\/3\] +site\/\[name\]\/index\.blade\.php +→ one\/index\.html/', $display);
        self::assertMatchesRegularExpression('/\[2\/3\] +site\/\[name\]\/index\.blade\.php +→ two\/index\.html/', $display);
        self::assertMatchesRegularExpression('/\[3\/3\] +site\/\[name\]\/cover\.svg +→ \[name\]\/cover\.svg/', $display);
        $rows = array_values(array_filter(explode("\n", $display), static fn(string $line): bool => str_contains($line, ' → ')));
        self::assertCount(3, $rows);
        self::assertSame(strpos($rows[0], '→'), strpos($rows[1], '→'));
        self::assertSame(strpos($rows[1], '→'), strpos($rows[2], '→'));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testRedirectedAnsiOutputUsesColorsWithoutCursorControls(): void
    {
        $this->write('site/index.php', 'Home');
        $this->write('site/app.css', 'body {}');
        $tester = $this->runCommand('build', decorated: true);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("\x1b[", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringNotContainsString("\r", $tester->getDisplay());
        self::assertStringNotContainsString("\x1b[2K", $tester->getDisplay());
        self::assertStringNotContainsString('site/index.php →', $tester->getDisplay());
    }

    public function testVerboseColumnsStayAlignedAcrossCounterDigits(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->write('site/page-' . $i . '.php', 'Page');
        }
        $tester = $this->runCommand('build', ['--verbose' => true]);
        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay(true);
        $rows = array_values(array_filter(explode("\n", $display), static fn(string $line): bool => str_contains($line, ' → ')));
        self::assertCount(10, $rows);
        self::assertStringContainsString('[ 1/10]', $rows[0]);
        self::assertStringContainsString('[10/10]', $rows[9]);
        foreach ($rows as $row) {
            self::assertSame(strpos($rows[0], '→'), strpos($row, '→'));
        }
    }

    public function testEmptyBuildDoesNotDisplayAnIndeterminateProgressBar(): void
    {
        $tester = $this->runCommand('build', decorated: true);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('build    0 files', self::plain($tester->getDisplay()));
        self::assertStringContainsString('0 pages and 0 assets', self::plain($tester->getDisplay()));
        self::assertSame('', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{array<string, bool>, bool}> */
    public static function verbosityModes(): iterable
    {
        yield 'normal' => [[], true];
        yield 'quiet' => [['--quiet' => true], true];
        yield 'silent' => [['--silent' => true], false];
    }

    /** @param array<string, bool> $options */
    #[DataProvider('verbosityModes')]
    public function testErrorsUseStderrAndQuietKeepsDiagnostics(array $options, bool $visible): void
    {
        $this->write('site/index.blade.php', '<img src="./missing.svg">');
        $tester = $this->runCommand('build', $options);
        self::assertSame(1, $tester->getStatusCode());
        $error = $tester->getErrorOutput(true);
        if ($visible) {
            self::assertSame("\n  ✗ Error: Missing asset ./missing.svg\n    Source   site/index.blade.php\n    URL      /\n\n", $error);
            self::assertSame(1, substr_count($error, 'site/index.blade.php'));
            self::assertStringNotContainsString($this->root, $error);
        } else {
            self::assertSame('', $error);
        }
        self::assertStringNotContainsString('Error:', $tester->getDisplay());
        self::assertStringNotContainsString('publish', $tester->getDisplay());
        self::assertStringNotContainsString('Built ', $tester->getDisplay());
        if ($options !== []) {
            self::assertSame('', $tester->getDisplay());
        }
    }

    public function testQuietSuccessSuppressesProgressAndSummaryWithoutSkippingTheBuild(): void
    {
        $this->write('site/index.php', 'Home');
        foreach (['--quiet', '--silent'] as $option) {
            $tester = $this->runCommand('build', [$option => true], decorated: true);
            self::assertSame(0, $tester->getStatusCode());
            self::assertSame('', $tester->getDisplay());
            self::assertSame('', $tester->getErrorOutput());
            self::assertSame('Home', file_get_contents($this->root . '/_site/index.html'));
        }
    }

    public function testConfigurationErrorsKeepTheirExitCodeAndUseStderr(): void
    {
        $tester = $this->runCommand('build', ['--config' => $this->root . '/missing.php', '--quiet' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('✗ Error: Configuration file does not exist:', $tester->getErrorOutput());
        self::assertStringContainsString('/missing.php', $tester->getErrorOutput());
    }

    public function testVerboseErrorsKeepTheFullExceptionChainAndEscapeMarkup(): void
    {
        $this->write('site/index.php', '<?php throw new RuntimeException("<info>broken</info>");');
        $tester = $this->runCommand('build', ['--verbose' => true]);
        self::assertSame(1, $tester->getStatusCode());
        $error = $tester->getErrorOutput(true);
        self::assertStringStartsWith("\n  ✗ Error: <info>broken</info>\n    Source   site/index.php\n    URL      /\n\n", $error);
        self::assertStringContainsString('Stack trace:', $error);
        self::assertStringContainsString('Next Hanji\\BuildException:', $error);
        self::assertStringContainsString($this->root, $error);
        self::assertStringNotContainsString('broken', $tester->getDisplay());
    }

    public function testIncludedTemplateErrorsIdentifyTheOriginalIncludeAndDynamicUrl(): void
    {
        $this->write('site/[name]/@params.php', '<?php return ["one"];');
        $this->write('site/[name]/index.blade.php', '@include("broken")');
        $this->write('site/_includes/@broken.blade.php', '<span>@php throw new RuntimeException("Include failed"); @endphp</span>');
        $tester = $this->runCommand('build');
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame("\n  ✗ Error: Include failed\n    Source   site/_includes/@broken.blade.php\n    URL      /one/\n\n", $tester->getErrorOutput(true));
    }

    public function testIncludedAssetErrorsIdentifyTheReferenceOrigin(): void
    {
        $this->write('site/index.blade.php', '@include("broken")');
        $this->write('site/_includes/@broken.blade.php', '<img src="./missing.svg">');
        $tester = $this->runCommand('build');
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame("\n  ✗ Error: Missing asset ./missing.svg\n    Source   site/_includes/@broken.blade.php\n    URL      /\n\n", $tester->getErrorOutput(true));
    }

    public function testFailedDecoratedBuildNeverReportsCompleteProgressOrSuccess(): void
    {
        $this->write('site/index.blade.php', '<img src="./missing.svg">');
        $tester = $this->runCommand('build', decorated: true);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString('100%', $tester->getErrorOutput());
        self::assertStringNotContainsString('Built ', $tester->getDisplay());
        self::assertStringContainsString('Error:', self::plain($tester->getErrorOutput()));
        self::assertStringContainsString('Missing asset', self::plain($tester->getErrorOutput()));
    }

    public function testCleanReportsOnlyRemovedDirectoriesAndThenNothingToClean(): void
    {
        $this->write('site/index.php', 'Home');
        (new Builder())->build((new Config())->resolve($this->root));
        $tester = $this->runCommand('clean');
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("  ✓ remove   _site/\n  ✓ remove   .hanji/cache/\n\n  ✓ Cleaned 2 directories.", $tester->getDisplay(true));
        self::assertSame('', $tester->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->root . '/_site');
        self::assertDirectoryDoesNotExist($this->root . '/.hanji/cache');
        self::assertFileExists($this->root . '/site/index.php');
        $tester = $this->runCommand('clean');
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to clean.', $tester->getDisplay(true));
        self::assertStringNotContainsString('remove', $tester->getDisplay(true));
    }

    public function testCleanDoesNotClaimToRemoveMissingOutputOrForeignCache(): void
    {
        $this->write('site/index.php', 'Home');
        (new Builder())->build((new Config())->resolve($this->root));
        $this->files->remove($this->root . '/_site');
        $tester = $this->runCommand('clean');
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("  ✓ remove   .hanji/cache/\n\n  ✓ Cleaned 1 directory.", $tester->getDisplay(true));
        self::assertStringNotContainsString('remove   _site/', $tester->getDisplay(true));
        $this->write('.hanji/cache/user.txt', 'Keep');
        $tester = $this->runCommand('clean');
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to clean.', $tester->getDisplay(true));
        self::assertSame('Keep', file_get_contents($this->root . '/.hanji/cache/user.txt'));
    }

    public function testCleanRejectsUnownedOutputWithoutClaimingSuccess(): void
    {
        $this->write('_site/user.txt', 'Keep');
        $tester = $this->runCommand('clean');
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringNotContainsString('Cleaned', $tester->getDisplay());
        self::assertStringContainsString('not owned by Hanji', $tester->getErrorOutput());
        self::assertSame('Keep', file_get_contents($this->root . '/_site/user.txt'));
    }

    public function testInitKeepsSuccessOutputAndOverwriteErrorsUseStderr(): void
    {
        $this->files->remove($this->root);
        $tester = $this->runCommand('init', ['directory' => $this->root]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Created hanji.php', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertFileExists($this->root . '/hanji.php');
        self::assertFileDoesNotExist($this->root . '/README.md');
        $tester = $this->runCommand('init', ['directory' => $this->root, '--quiet' => true]);
        self::assertSame(2, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame("\n  ✗ Error: Initialization would overwrite hanji.php.\n\n", $tester->getErrorOutput(true));
    }

    /** @param array<string, string|bool> $arguments */
    private function runCommand(string $command, array $arguments = [], bool $decorated = false): ApplicationTester
    {
        $application = Console::application();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $input = ['command' => $command];
        if ($command !== 'init') {
            $input['--config'] = $this->root . '/hanji.php';
        }
        $tester->run([...$input, ...$arguments], ['capture_stderr_separately' => true, 'decorated' => $decorated]);
        return $tester;
    }

    private function write(string $path, string $content): void
    {
        $this->files->dumpFile($this->root . '/' . $path, $content);
    }

    private static function plain(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $text) ?? $text;
    }
}
