<?php

declare(strict_types=1);

namespace Hanji\Asset;

use Hanji\BuildException;
use Hanji\Settings;
use Hanji\Support\Paths;

final readonly class AssetResolver
{
    /** @param array<string, list<array{url: string, params: array<string, string>}>> $manifest */
    public function __construct(private Settings $settings, private array $manifest) {}

    /** @param array<string, string> $params */
    public function resolve(string $reference, string $source, array $params): string
    {
        if ($reference === '' || $reference[0] === '/' || $reference[0] === '#' || $reference[0] === '?' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) {
            return $reference;
        }
        $length = strcspn($reference, '?#');
        $path = substr($reference, 0, $length);
        $suffix = substr($reference, $length);
        if (preg_match('/%(?:2f|5c)|%(?![0-9a-f]{2})/i', $path) || str_contains($path, '\\')) {
            throw new BuildException("Invalid relative asset URL {$reference} in {$source}");
        }
        $absolute = Paths::absolute(rawurldecode($path), dirname($source));
        if (!Paths::within($absolute, $this->settings->input)) {
            throw new BuildException("Asset escapes the input directory: {$reference} in {$source}");
        }
        if (str_starts_with(basename($absolute), '@')) {
            throw new BuildException("Special files cannot be public assets: {$reference} in {$source}");
        }
        $targets = $this->manifest[$absolute] ?? [];
        $targets = array_values(array_filter($targets, static function (array $target) use ($params): bool {
            foreach ($target['params'] as $key => $value) {
                if (isset($params[$key]) && $params[$key] !== $value) {
                    return false;
                }
            }
            return true;
        }));
        if (count($targets) !== 1) {
            throw new BuildException((count($targets) === 0 ? 'Missing' : 'Ambiguous dynamic') . " asset {$reference} in {$source}");
        }
        return $targets[0]['url'] . $suffix;
    }
}
