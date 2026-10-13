<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Console\StatusLine;
use Hanji\Console\Style;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleStyleTest extends TestCase
{
    public function testLiveSpinnerAdvancesOnWorkAndFinishesWithTiming(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $live = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $now = 10.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $line = new StatusLine($output, $live, true, 'build', '0/3 files', 49, $clock);
        self::assertStringContainsString('⠋', $live->fetch());
        $now += 0.12;
        $line->update('1/3 files');
        $frame = $live->fetch();
        self::assertStringContainsString('⠙', $frame);
        self::assertStringContainsString('1/3 files', $frame);
        self::assertStringContainsString("\r\x1b[2K", $frame);
        $now += 0.25;
        $line->update('3/3 files');
        $line->finish();
        $complete = $output->fetch();
        self::assertStringContainsString('✓', $complete);
        self::assertStringContainsString('3/3 files', $complete);
        self::assertStringContainsString('370ms', $complete);
        self::assertStringNotContainsString('✗', $complete);
    }

    public function testFailedStageDoesNotEmitASuccessCheck(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $live = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $line = new StatusLine($output, $live, true, 'build', '0/1 files', 49);
        $line->finish(true);
        self::assertSame('', $output->fetch());
        $failure = $live->fetch();
        self::assertStringContainsString('✗', $failure);
        self::assertStringNotContainsString('✓', $failure);
    }

    public function testStaticOutputContainsNoAnimationCodesOrRepeatedRows(): void
    {
        $output = new BufferedOutput();
        $live = new BufferedOutput();
        $line = new StatusLine($output, $live, false, 'build', '2 files', 49);
        $line->update('1/2 files');
        $line->update('2/2 files');
        $line->finish();
        self::assertSame('  › build    2 files' . PHP_EOL, $output->fetch());
        self::assertSame('', $live->fetch());
    }

    public function testNarrowRowsRespectTerminalColumnsAndKeepUnicodePathsValid(): void
    {
        $path = 'very-long-directory/문서/한지-서식.blade.php';
        foreach ([12, 24, 49] as $width) {
            $output = new BufferedOutput();
            $output->writeln(Style::row('✓', 'prepare', $path, 'green', '120ms', $width));
            $text = rtrim($output->fetch(), "\r\n");
            self::assertLessThanOrEqual($width, mb_strwidth($text, 'UTF-8'));
            self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        }
        self::assertStringEndsWith('.blade.php', Style::fit($path, 24));
    }

    public function testDynamicValuesCannotInjectConsoleStyles(): void
    {
        $output = new BufferedOutput();
        $output->writeln(Style::row('✓', 'build', '<info>literal</info>'));
        self::assertStringContainsString('<info>literal</info>', $output->fetch());
    }
}
