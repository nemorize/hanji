<?php

declare(strict_types=1);

namespace Hanji\Support;

use Hanji\BuildException;

final class Maps
{
    /** @return array<string, mixed> */
    public static function validate(mixed $value, string $source): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new BuildException("{$source}: expected a data map.");
        }
        foreach ($value as $key => $_) {
            if (!is_string($key)) {
                throw new BuildException("{$source}: data keys must be strings.");
            }
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $next
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $next): array
    {
        foreach ($next as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value) && isset($base[$key]) && is_array($base[$key]) && $base[$key] !== [] && !array_is_list($base[$key])) {
                $base[$key] = self::merge(self::validate($base[$key], $key), self::validate($value, $key));
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
