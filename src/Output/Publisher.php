<?php

declare(strict_types=1);

namespace Hanji\Output;

use Hanji\BuildException;
use Hanji\Settings;
use Hanji\Support\Paths;
use Symfony\Component\Filesystem\Filesystem;

/** Staged directory replacement, with a recoverable journal and exclusive build lock. */
final class Publisher
{
    private Filesystem $files;
    private string $state;
    private string $journal;
    private string $prefix;
    /** @var resource|null */
    private $lock = null;
    private ?string $stage = null;

    public function __construct(private readonly Settings $settings)
    {
        $this->files = new Filesystem();
        $key = hash('sha256', $settings->output);
        $directory = $settings->root . '/.hanji/state';
        $this->files->mkdir($directory);
        $this->state = $directory . '/' . $key . '.json';
        $this->journal = $directory . '/' . $key . '.journal.json';
        $this->prefix = dirname($settings->output) . '/.hanji-build-' . substr($key, 0, 12) . '-';
        $lock = fopen($directory . '/' . $key . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new BuildException('Another build or clean is already running for this output.');
        }
        $this->lock = $lock;
        $this->recover();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function begin(): string
    {
        $this->assertOwned();
        $this->assertCache();
        $this->stage = $this->prefix . bin2hex(random_bytes(8)) . '-stage';
        $this->files->mkdir($this->stage);
        return $this->stage;
    }

    public function publish(): void
    {
        $stage = $this->stage ?? throw new BuildException('No staged output.');
        $generation = bin2hex(random_bytes(12));
        $backup = $this->prefix . $generation . '-backup';
        $previous = is_dir($this->settings->output);
        $transaction = ['stage' => $stage, 'backup' => $backup, 'previous' => $previous, 'generation' => $generation];
        $this->files->dumpFile($this->journal, json_encode($transaction, JSON_THROW_ON_ERROR));
        try {
            if ($previous) {
                $this->files->rename($this->settings->output, $backup);
            }
            $this->files->rename($stage, $this->settings->output);
            $this->files->dumpFile($this->state, json_encode(['root' => $this->settings->root, 'output' => $this->settings->output, 'generation' => $generation], JSON_THROW_ON_ERROR));
            $this->recover();
            $this->stage = null;
        } catch (\Throwable $error) {
            $this->recover();
            throw new BuildException('Cannot publish output: ' . $error->getMessage(), 0, $error);
        }
    }

    /** @return list<string> Removed output and cache directories. */
    public function clean(): array
    {
        $this->assertOwned();
        $removed = [];
        if (is_file($this->state)) {
            $exists = is_dir($this->settings->output);
            $this->files->remove([$this->settings->output, $this->state]);
            if ($exists) {
                $removed[] = $this->settings->output;
            }
        }
        if ($this->cacheOwned()) {
            $this->files->remove($this->settings->cache);
            $removed[] = $this->settings->cache;
        }
        return $removed;
    }

    public function close(): void
    {
        if ($this->stage !== null) {
            $this->files->remove($this->stage);
            $this->stage = null;
        }
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private function assertOwned(): void
    {
        if (is_link($this->settings->output) || (file_exists($this->settings->output) && !is_dir($this->settings->output))) {
            throw new BuildException('Output must be a real directory.');
        }
        if (is_file($this->state)) {
            $state = $this->read($this->state);
            if (($state['root'] ?? null) !== $this->settings->root || ($state['output'] ?? null) !== $this->settings->output) {
                throw new BuildException('Output ownership does not match this project.');
            }
        } elseif (is_dir($this->settings->output) && (scandir($this->settings->output) ?: []) !== ['.', '..']) {
            throw new BuildException('Refusing to replace an output directory not owned by Hanji.');
        }
    }

    private function assertCache(): void
    {
        if (is_dir($this->settings->cache) && !$this->cacheOwned() && (scandir($this->settings->cache) ?: []) !== ['.', '..']) {
            throw new BuildException('Refusing to use a nonempty cache directory not owned by Hanji.');
        }
        $this->files->dumpFile($this->settings->cache . '/.hanji-cache.json', json_encode(['root' => $this->settings->root, 'output' => $this->settings->output], JSON_THROW_ON_ERROR));
    }

    private function cacheOwned(): bool
    {
        $marker = $this->settings->cache . '/.hanji-cache.json';
        if (!is_file($marker)) {
            return false;
        }
        $data = $this->read($marker);
        return ($data['root'] ?? null) === $this->settings->root && ($data['output'] ?? null) === $this->settings->output;
    }

    private function recover(): void
    {
        if (!is_file($this->journal)) {
            return;
        }
        $journal = $this->read($this->journal);
        $stage = $journal['stage'] ?? null;
        $backup = $journal['backup'] ?? null;
        if (!is_string($stage) || !is_string($backup) || !str_starts_with($stage, $this->prefix) || !str_starts_with($backup, $this->prefix) || dirname($stage) !== dirname($this->settings->output) || dirname($backup) !== dirname($this->settings->output) || !Paths::within($stage, $this->settings->root)) {
            throw new BuildException('Invalid build recovery journal.');
        }
        $state = is_file($this->state) ? $this->read($this->state) : [];
        if (($state['generation'] ?? null) === ($journal['generation'] ?? false)) {
            $this->files->remove([$stage, $backup, $this->journal]);
            return;
        }
        if (is_dir($backup)) {
            $this->files->remove($this->settings->output);
            $this->files->rename($backup, $this->settings->output);
        } elseif (($journal['previous'] ?? true) === false && !is_dir($stage)) {
            $this->files->remove($this->settings->output);
        }
        $this->files->remove([$stage, $this->journal]);
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new BuildException("Invalid state: {$path}");
        }
        /** @var array<string, mixed> $value */
        return $value;
    }
}
