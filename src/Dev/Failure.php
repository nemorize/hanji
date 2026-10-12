<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\BuildException;
use Hanji\Diagnostic;

final class Failure extends BuildException
{
    public function __construct(public readonly Diagnostic $diagnostic, public readonly bool $configuration = false)
    {
        parent::__construct($diagnostic->message, source: $diagnostic->source, url: $diagnostic->url);
    }
}
