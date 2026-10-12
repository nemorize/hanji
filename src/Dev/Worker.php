<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\BuildProgress;
use Hanji\Diagnostic;
use Hanji\Support\Maps;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class Worker
{
    private readonly Process $process;
    private readonly string $request;
    private readonly string $response;
    private string $buffer = '';
    private ?Project $project = null;

    public function __construct(string $directory, ?string $config, string $session)
    {
        $id = bin2hex(random_bytes(8));
        $this->request = $session . '/request-' . $id . '.json';
        $this->response = $session . '/response-' . $id . '.json';
        Protocol::write($this->request, ['directory' => $directory, 'config' => $config, 'response' => $this->response]);
        $this->process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', __DIR__ . '/build.php', $this->request], $directory, timeout: null);
        $this->process->start();
    }

    /** @param callable(Project|BuildProgress): void $onProgress */
    public function poll(callable $onProgress): ?Outcome
    {
        $running = $this->process->isRunning();
        $this->buffer .= $this->process->getIncrementalOutput();
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 1);
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }
            if (($event['type'] ?? '') === 'project') {
                $this->project = Project::decode($event['data'] ?? null);
                $onProgress($this->project);
            } elseif (($event['type'] ?? '') === 'progress') {
                $data = Maps::validate($event['data'] ?? null, 'Build progress');
                $stage = $data['stage'] ?? null;
                $completed = $data['completed'] ?? null;
                $total = $data['total'] ?? null;
                $source = $data['source'] ?? null;
                $output = $data['output'] ?? null;
                if (!is_string($stage) || !is_int($completed) || !is_int($total) || ($source !== null && !is_string($source)) || ($output !== null && !is_string($output))) {
                    throw new BuildException('Invalid development build progress.');
                }
                $onProgress(new BuildProgress($stage, $completed, $total, $source, $output));
            }
        }
        if ($running) {
            return null;
        }
        if (is_file($this->response)) {
            $result = Outcome::decode(Protocol::read($this->response));
        } else {
            $message = trim($this->process->getErrorOutput());
            $result = new Outcome($this->project, error: new Diagnostic($message === '' ? 'Development build process exited without a result.' : $message));
        }
        $this->close();
        return $result;
    }

    public function close(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0.5);
        }
        (new Filesystem())->remove([$this->request, $this->response]);
    }

    public function __destruct()
    {
        $this->close();
    }
}
