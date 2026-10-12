<?php

declare(strict_types=1);

namespace Hanji;

class BuildException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly ?string $source = null, public readonly ?string $url = null, public readonly ?string $hint = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
