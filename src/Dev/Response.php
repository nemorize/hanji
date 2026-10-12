<?php

declare(strict_types=1);

namespace Hanji\Dev;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status = 200, public array $headers = [], public string $body = '', public ?string $file = null) {}

    public function send(bool $head = false): void
    {
        $stream = $this->file === null ? null : @fopen($this->file, 'rb');
        if ($this->file !== null && $stream === false) {
            http_response_code(404);
            return;
        }
        http_response_code($this->status);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        $length = is_resource($stream) ? (fstat($stream)['size'] ?? null) : strlen($this->body);
        if (is_int($length)) {
            header('Content-Length: ' . $length);
        }
        if (!$head) {
            if (is_resource($stream)) {
                fpassthru($stream);
            } else {
                echo $this->body;
            }
        }
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
