<?php

declare(strict_types=1);

namespace Hanji\Output;

use Hanji\BuildException;

final class Extension
{
    public static function validate(string $extension): void
    {
        if (!preg_match('/^[a-z][a-z0-9]*$/D', $extension) || in_array($extension, ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar'], true)) {
            throw new BuildException("invalid output extension {$extension}.");
        }
    }
}
