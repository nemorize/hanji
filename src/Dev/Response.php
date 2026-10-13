<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Http\Server\Response as HttpResponse;

final readonly class Response
{
    /** @param array<non-empty-string, string> $headers */
    public function __construct(public int $status = 200, public array $headers = [], public string $body = '', public ?string $file = null) {}

    public function http(): HttpResponse
    {
        $stream = $this->file === null ? null : @fopen($this->file, 'rb');
        if ($this->file !== null && $stream === false) {
            return new HttpResponse(404, ['Cache-Control' => 'no-store']);
        }
        $response = new HttpResponse($this->status, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', ...$this->headers], is_resource($stream) ? new ReadableResourceStream($stream) : $this->body);
        $length = is_resource($stream) ? (fstat($stream)['size'] ?? null) : strlen($this->body);
        if (is_int($length)) {
            $response->setHeader('Content-Length', (string) $length);
        }
        return $response;
    }
}
