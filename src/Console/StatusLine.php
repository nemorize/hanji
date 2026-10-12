<?php

declare(strict_types=1);

namespace Hanji\Console;

use Symfony\Component\Console\Output\OutputInterface;

/** Redraws a single terminal line on work events without delaying the build. */
final class StatusLine
{
    private const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private readonly float $started;
    private float $drawn = 0;

    /** @param (\Closure(): float)|null $clock */
    public function __construct(private readonly OutputInterface $output, private readonly OutputInterface $live, private readonly bool $animated, private readonly string $label, private string $detail, private readonly int $width, private readonly ?\Closure $clock = null)
    {
        $this->started = $this->now();
        if ($animated) {
            $this->draw(true);
        } else {
            $output->writeln(Style::row('›', $label, $detail));
        }
    }

    public function update(string $detail): void
    {
        $this->detail = $detail;
        if ($this->animated) {
            $this->draw();
        }
    }

    public function finish(bool $failed = false): void
    {
        if (!$this->animated) {
            return;
        }
        $this->live->write("\r\x1b[2K", false, OutputInterface::OUTPUT_RAW);
        ($failed ? $this->live : $this->output)->writeln(Style::row($failed ? '✗' : '✓', $this->label, $this->detail, $failed ? 'red' : 'green', Style::duration($this->now() - $this->started), $this->width));
    }

    private function draw(bool $force = false): void
    {
        $now = $this->now();
        if (!$force && $now - $this->drawn < 0.08) {
            return;
        }
        $this->drawn = $now;
        $frame = self::FRAMES[(int) floor(($now - $this->started) / 0.08) % count(self::FRAMES)];
        $this->live->write("\r\x1b[2K", false, OutputInterface::OUTPUT_RAW);
        $this->live->write(Style::row($frame, $this->label, $this->detail, 'magenta', Style::duration($now - $this->started), $this->width));
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1e9;
    }
}
