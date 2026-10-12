<?php

declare(strict_types=1);

namespace Hanji\Output;

/** Carries source provenance through nested rendering without resolving URLs. */
final class Origins
{
    private readonly string $key;
    /** @var array<int, string> */
    private array $sources = [];

    public function __construct()
    {
        $this->key = bin2hex(random_bytes(8));
    }

    public function tag(string $tag, string $source): string
    {
        $id = count($this->sources);
        $this->sources[$id] = $source;
        return '<!--hanji-origin:' . $this->key . ':' . $id . '-->' . $tag;
    }

    public function source(string $content, int $offset, string $fallback): string
    {
        $start = max(0, $offset - 80);
        $prefix = substr($content, $start, $offset - $start);
        if (preg_match('/<!--hanji-origin:' . $this->key . ':([0-9]+)-->$/D', $prefix, $match)) {
            return $this->sources[(int) $match[1]] ?? $fallback;
        }
        return $fallback;
    }

    public function strip(string $content): string
    {
        // Also remove provenance from strings escaped once or repeatedly by templates.
        return preg_replace('/(?:<!--|&(?:amp;)*lt;!--)hanji-origin:' . $this->key . ':[0-9]+(?:-->|--&(?:amp;)*gt;)/', '', $content) ?? $content;
    }
}
