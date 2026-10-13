<?php

declare(strict_types=1);

namespace Hanji\Dev\Watch;

use Hanji\BuildException;

final class WindowsNotifications
{
    /** @return list<string> */
    public static function paths(string $root, string $data): array
    {
        $paths = [];
        $offset = 0;
        $size = strlen($data);
        while ($offset < $size) {
            if ($offset + 12 > $size) {
                throw new BuildException('Incomplete Windows file notification header.');
            }
            $event = unpack('Vnext/Vaction/Vlength', substr($data, $offset, 12));
            if ($event === false || !is_int($event['next']) || !is_int($event['action']) || !is_int($event['length']) || $event['length'] % 2 !== 0 || $event['length'] === 0 || $offset + 12 + $event['length'] > $size || $event['action'] < 1 || $event['action'] > 5) {
                throw new BuildException('Invalid Windows file notification.');
            }
            $name = mb_convert_encoding(substr($data, $offset + 12, $event['length']), 'UTF-8', 'UTF-16LE');
            $name = str_replace('\\', '/', $name);
            if (str_starts_with($name, '/') || preg_match('~[\x00-\x1f]|(?:^|/)\.\.?($|/)|:~', $name)) {
                throw new BuildException('Invalid Windows file notification path.');
            }
            $paths[] = rtrim(str_replace('\\', '/', $root), '/') . '/' . $name;
            if ($event['next'] === 0) {
                break;
            }
            if ($event['next'] < 12 + $event['length'] || $event['next'] % 4 !== 0 || $offset + $event['next'] >= $size) {
                throw new BuildException('Invalid Windows file notification offset.');
            }
            $offset += $event['next'];
        }
        return array_values(array_unique($paths));
    }

    public static function nativePath(string $path): string
    {
        $path = str_replace('/', '\\', $path);
        if (str_starts_with($path, '\\\\?\\')) {
            return $path;
        }
        if (str_starts_with($path, '\\\\')) {
            return '\\\\?\\UNC\\' . substr($path, 2);
        }
        if (preg_match('/^[A-Za-z]:\\\\/', $path)) {
            return '\\\\?\\' . $path;
        }
        throw new BuildException('Windows native watching requires an absolute path.');
    }
}
