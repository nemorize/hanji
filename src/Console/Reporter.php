<?php

declare(strict_types=1);

namespace Hanji\Console;

use Hanji\BuildProgress;
use Hanji\BuildResult;
use Hanji\Diagnostic;
use Hanji\Dev\Project;
use Hanji\Settings;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;

final class Reporter
{
    private readonly OutputInterface $errors;
    private Settings|Project|null $settings = null;
    private ?StatusLine $status = null;
    private ?string $stage = null;
    private readonly Terminal $terminal;

    public function __construct(private readonly OutputInterface $output, private string $root)
    {
        $this->errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->terminal = new Terminal();
    }

    public function start(string $command, string $version): void
    {
        $this->output->writeln('');
        $this->output->writeln('  ' . Style::color('HANJI', 'magenta', true) . '  ' . Style::color('v' . $version, 'gray') . '  ' . Style::color($command, 'cyan', true));
        $this->output->writeln('');
    }

    public function project(Settings|Project $settings): void
    {
        $this->settings = $settings;
        $this->root = $settings->root;
    }

    public function progress(BuildProgress $event): void
    {
        if ($this->output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL) {
            return;
        }
        $label = match ($event->stage) {
            'scanning' => 'scan',
            'preparing' => 'prepare',
            'building' => 'build',
            'publishing' => 'publish',
            default => $event->stage,
        };
        $detail = match ($event->stage) {
            'scanning' => $this->path($this->settings->input ?? $this->root),
            'preparing' => $event->source === null ? 'pages · assets' : $this->path($event->source),
            'building' => $this->fileProgress($event),
            'publishing' => $this->path($this->settings->output ?? $this->root),
            default => '',
        };
        if ($this->stage !== $event->stage) {
            $this->endProgress();
            $this->stage = $event->stage;
            $this->status = new StatusLine($this->output, $this->errors, $this->animated(), $label, $detail, max(1, $this->terminal->getWidth() - 1));
        } else {
            $this->status?->update($detail);
        }
        if ($event->stage === 'building' && $event->source !== null) {
            $this->file($event);
        }
    }

    public function built(BuildResult $result): void
    {
        $this->endProgress();
        $this->output->writeln('');
        $this->output->writeln('  ' . Style::color('✓', 'green') . ' ' . Style::color('Built', 'green', true) . ' ' . Style::color(self::count($result->pages, 'page'), 'cyan') . ' and ' . Style::color(self::count($result->assets, 'asset'), 'magenta') . ' in ' . Style::color(Style::duration($result->seconds), 'yellow', true));
        $this->field($this->output, 'Output', $this->path($result->output) . '/');
        $this->output->writeln('');
    }

    /** @param list<string> $removed */
    public function cleaned(array $removed): void
    {
        if ($removed === []) {
            $this->output->writeln(Style::row('·', 'clean', 'Nothing to clean.', 'yellow'));
            $this->output->writeln('');
            return;
        }
        foreach ($removed as $path) {
            $this->output->writeln(Style::row('✓', 'remove', $this->path($path) . '/', 'green'));
        }
        $this->output->writeln('');
        $this->output->writeln('  ' . Style::color('✓ Cleaned', 'green', true) . ' ' . Style::color(self::count(count($removed), 'directory', 'directories'), 'cyan') . '.');
        $this->output->writeln('');
    }

    public function initialized(string $directory): void
    {
        $this->output->writeln('  ' . Style::color('✓ Created', 'green', true) . ' hanji.php and a Blade/CSS starter site.');
        $this->field($this->output, 'Project', $this->path($directory));
        $this->field($this->output, 'Next', 'Run hanji build in that directory.');
        $this->output->writeln('');
    }

    public function error(\Throwable $error): void
    {
        $this->diagnostic(Diagnostic::from($error, $this->settings?->cache));
    }

    public function diagnostic(Diagnostic $error): void
    {
        $this->endProgress(true);
        $this->errors->writeln('', OutputInterface::VERBOSITY_QUIET);
        $this->errors->writeln('  ' . Style::color('✗ Error:', 'red', true) . ' ' . OutputFormatter::escape($this->shorten($error->message)), OutputInterface::VERBOSITY_QUIET);
        if ($error->source !== null) {
            $this->field($this->errors, 'Source', $this->path($error->source), OutputInterface::VERBOSITY_QUIET);
        }
        if ($error->url !== null) {
            $this->field($this->errors, 'URL', $error->url, OutputInterface::VERBOSITY_QUIET);
        }
        foreach ($error->hints as $hint) {
            $this->field($this->errors, 'Hint', $hint, OutputInterface::VERBOSITY_QUIET);
        }
        $this->errors->writeln('', OutputInterface::VERBOSITY_QUIET);
        $this->errors->writeln(Style::color($error->trace, 'gray'), OutputInterface::VERBOSITY_VERBOSE);
    }

    public function serving(string $url, string $watcher): void
    {
        $this->output->writeln('');
        $this->field($this->output, 'Local', $url);
        $this->field($this->output, 'Watch', $this->path($this->settings->input ?? $this->root));
        $this->field($this->output, 'Watcher', $watcher);
        $this->field($this->output, 'Updates', 'WebSocket · CSS HMR · live reload');
        $this->field($this->output, 'Stop', 'Ctrl+C');
        $this->output->writeln('');
    }

    /** @param list<string> $paths */
    public function changed(array $paths): void
    {
        $this->output->writeln(Style::row('↻', 'watch', self::count(count($paths), 'file') . ' changed', 'yellow'));
        foreach ($paths as $path) {
            $this->output->writeln('    ' . Style::color($this->path($path), 'gray'), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    public function requests(string $output): void
    {
        if ($output !== '') {
            $this->output->writeln(Style::color(trim($output), 'gray'), OutputInterface::VERBOSITY_VERY_VERBOSE);
        }
    }

    private function endProgress(bool $failed = false): void
    {
        if (!$failed && $this->stage === 'preparing') {
            $this->status?->update('pages · assets');
        }
        $this->status?->finish($failed);
        $this->status = null;
        $this->stage = null;
    }

    private function animated(): bool
    {
        return $this->output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL && $this->errors->isDecorated() && $this->errors instanceof StreamOutput && stream_isatty($this->errors->getStream()) && !getenv('CI');
    }

    private function fileProgress(BuildProgress $event): string
    {
        if (!$this->animated()) {
            return self::count($event->total, 'file');
        }
        if ($event->total === 0) {
            return '0 files';
        }
        $percent = (int) floor($event->completed / $event->total * 100);
        $bars = (int) floor($event->completed / $event->total * 12);
        return sprintf('%d/%d files  %s%s  %3d%%', $event->completed, $event->total, str_repeat('━', $bars), str_repeat('─', 12 - $bars), $percent);
    }

    private function file(BuildProgress $event): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }
        $source = $this->path($event->source ?? '');
        $target = $event->output ?? '';
        $digits = strlen((string) $event->total);
        $counter = '[' . str_pad((string) $event->completed, $digits, ' ', STR_PAD_LEFT) . '/' . $event->total . ']';
        $width = max(10, min(36, intdiv($this->terminal->getWidth() - 24, 2)));
        if ($this->output->isDecorated() && $this->output instanceof StreamOutput && stream_isatty($this->output->getStream())) {
            $source = Style::fit($source, $width);
            $target = Style::fit($target, max(1, $this->terminal->getWidth() - 10 - strlen($counter) - $width));
        }
        $this->output->writeln('    ' . Style::color($counter, 'gray') . '  ' . Style::color(Style::pad($source, $width), 'cyan') . ' ' . Style::color('→', 'gray') . ' ' . Style::color($target, 'green'), OutputInterface::VERBOSITY_VERBOSE);
    }

    private function field(OutputInterface $output, string $label, string $value, int $verbosity = OutputInterface::VERBOSITY_NORMAL): void
    {
        $output->writeln('    ' . Style::color(Style::pad($label, 8), 'gray') . ' ' . Style::color($value, 'cyan'), $verbosity);
    }

    private function path(string $path): string
    {
        return $path === $this->root ? '.' : $this->shorten($path);
    }

    private function shorten(string $text): string
    {
        return str_replace(rtrim($this->root, '/') . '/', '', $text);
    }

    private static function count(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }
}
