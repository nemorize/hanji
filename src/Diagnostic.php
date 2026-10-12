<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Support\Maps;
use Hanji\Support\Paths;

final readonly class Diagnostic
{
    /** @param list<string> $hints */
    public function __construct(public string $message, public ?string $source = null, public ?string $url = null, public array $hints = [], public string $trace = '') {}

    public static function from(\Throwable $error, ?string $cache = null): self
    {
        $cause = $error;
        $source = null;
        $url = null;
        $hints = [];
        do {
            if ($cause instanceof BuildException) {
                if ($cause->source !== null && ($cache === null || !Paths::within($cause->source, $cache))) {
                    $source = $cause->source;
                }
                $url ??= $cause->url;
                if ($cause->hint !== null) {
                    $hints[] = $cause->hint;
                }
            }
            $previous = $cause->getPrevious();
            if ($previous === null) {
                break;
            }
            $cause = $previous;
        } while (true);
        $message = $cause->getMessage();
        if ($source !== null && str_ends_with($message, ' in ' . $source)) {
            $message = substr($message, 0, -strlen(' in ' . $source));
        }
        return new self($message, $source, $url, array_values(array_unique($hints)), (string) $error);
    }

    public static function decode(mixed $value): self
    {
        $data = Maps::validate($value, 'Build diagnostic');
        $message = $data['message'] ?? null;
        $source = $data['source'] ?? null;
        $url = $data['url'] ?? null;
        $trace = $data['trace'] ?? '';
        $hints = $data['hints'] ?? [];
        if (!is_string($message) || ($source !== null && !is_string($source)) || ($url !== null && !is_string($url)) || !is_string($trace) || !is_array($hints) || !array_is_list($hints)) {
            throw new BuildException('Invalid build diagnostic.');
        }
        foreach ($hints as $hint) {
            if (!is_string($hint)) {
                throw new BuildException('Invalid build diagnostic hint.');
            }
        }
        return new self($message, $source, $url, $hints, $trace);
    }
}
