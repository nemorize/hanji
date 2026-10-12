<?php

declare(strict_types=1);

namespace Hanji\Plugin;

use Hanji\BuildException;
use Hanji\Support\Maps;

final class Variables
{
    /** @param array<string, mixed> $variables */
    public static function validate(array $variables): void
    {
        Maps::validate($variables, 'Plugin context');
        foreach (array_keys($variables) as $key) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) || str_starts_with($key, '__') || in_array($key, ['site', 'page', 'collections', 'hanji', 'GLOBALS', 'this'], true)) {
                throw new BuildException("Invalid or reserved plugin context variable: {$key}");
            }
        }
    }
}
