<?php

declare(strict_types=1);

namespace Hanji\Asset;

/** Rewrites URL tokens and import strings without changing other CSS bytes. */
final class Css
{
    private const WHITESPACE = " \t\n\r\f";

    /** @param callable(string): string $resolve */
    public static function rewriteReferences(string $source, callable $resolve): string
    {
        $result = '';
        $cursor = 0;
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            $character = $source[$i];
            if (substr($source, $i, 2) === '/*') {
                $i = self::trivia($source, $i);
                continue;
            }
            if ($character === '"' || $character === "'") {
                $i = self::quoted($source, $i)['after'] ?? $length;
                continue;
            }
            $reference = null;
            if ($character === '@') {
                [$name, $i] = self::identifier($source, $i + 1);
                $start = self::trivia($source, $i);
                if (strtolower($name) === 'import' && in_array($source[$start] ?? '', ['"', "'"], true)) {
                    $reference = self::quoted($source, $start);
                }
            } elseif (self::nameCharacter($character) || $character === '\\') {
                [$name, $after] = self::identifier($source, $i);
                $i = max($i + 1, $after);
                if (strtolower($name) === 'url' && ($source[$i] ?? '') === '(') {
                    $reference = self::url($source, $i + 1);
                }
            } else {
                ++$i;
            }
            if ($reference === null) {
                continue;
            }
            $i = $reference['after'];
            $url = $resolve($reference['url']);
            if ($url !== $reference['url']) {
                $result .= substr($source, $cursor, $reference['start'] - $cursor) . self::encode($url, $reference['quote']);
                $cursor = $reference['end'];
            }
        }
        return $result . substr($source, $cursor);
    }

    /** @return array{start: int, end: int, after: int, url: string, quote: string}|null */
    private static function quoted(string $source, int $start): ?array
    {
        $quote = $source[$start++];
        $url = '';
        for ($i = $start, $length = strlen($source); $i < $length;) {
            $character = $source[$i];
            if ($character === $quote) {
                return ['start' => $start, 'end' => $i, 'after' => $i + 1, 'url' => $url, 'quote' => $quote];
            }
            if (str_contains("\n\r\f", $character)) {
                return null;
            }
            if ($character === '\\') {
                [$escaped, $i] = self::escape($source, $i);
                $url .= $escaped;
            } else {
                $url .= $character;
                ++$i;
            }
        }
        return null;
    }

    /** @return array{start: int, end: int, after: int, url: string, quote: string}|null */
    private static function url(string $source, int $start): ?array
    {
        $start += strspn($source, self::WHITESPACE, $start);
        if (in_array($source[$start] ?? '', ['"', "'"], true)) {
            $reference = self::quoted($source, $start);
            if ($reference === null) {
                return null;
            }
            $end = self::trivia($source, $reference['after']);
            if (($source[$end] ?? '') !== ')') {
                return null;
            }
            return [...$reference, 'after' => $end + 1];
        }
        $url = '';
        for ($i = $start, $length = strlen($source); $i < $length;) {
            $character = $source[$i];
            if ($character === ')' || str_contains(self::WHITESPACE, $character)) {
                $end = $i + strspn($source, self::WHITESPACE, $i);
                return ($source[$end] ?? '') === ')' ? ['start' => $start, 'end' => $i, 'after' => $end + 1, 'url' => $url, 'quote' => ''] : null;
            }
            if (str_contains("\"'(\x7f", $character) || ord($character) < 0x20) {
                return null;
            }
            if ($character === '\\') {
                if (!isset($source[$i + 1]) || str_contains("\n\r\f", $source[$i + 1])) {
                    return null;
                }
                [$escaped, $i] = self::escape($source, $i);
                $url .= $escaped;
            } else {
                $url .= $character;
                ++$i;
            }
        }
        return null;
    }

    /** @return array{string, int} Decoded identifier and its end offset. */
    private static function identifier(string $source, int $start): array
    {
        $name = '';
        for ($i = $start, $length = strlen($source); $i < $length;) {
            $character = $source[$i];
            if (self::nameCharacter($character)) {
                $name .= $character;
                ++$i;
            } elseif ($character === '\\' && isset($source[$i + 1]) && !str_contains("\n\r\f", $source[$i + 1])) {
                [$escaped, $i] = self::escape($source, $i);
                $name .= $escaped;
            } else {
                break;
            }
        }
        return [$name, $i];
    }

    private static function nameCharacter(string $character): bool
    {
        return $character === '-' || $character === '_' || ctype_alnum($character) || ord($character) >= 0x80;
    }

    private static function trivia(string $source, int $start): int
    {
        $length = strlen($source);
        while ($start < $length) {
            $start += strspn($source, self::WHITESPACE, $start);
            if (substr($source, $start, 2) !== '/*') {
                break;
            }
            $end = strpos($source, '*/', $start + 2);
            $start = $end === false ? $length : $end + 2;
        }
        return $start;
    }

    /** @return array{string, int} Decoded CSS escape and its end offset. */
    private static function escape(string $source, int $start): array
    {
        $i = $start + 1;
        if (!isset($source[$i])) {
            return ["\u{FFFD}", $i];
        }
        if (str_contains("\n\r\f", $source[$i])) {
            return ['', $i + (substr($source, $i, 2) === "\r\n" ? 2 : 1)];
        }
        $digits = strspn($source, '0123456789abcdefABCDEF', $i, 6);
        if ($digits === 0) {
            return [$source[$i], $i + 1];
        }
        $codepoint = (int) hexdec(substr($source, $i, $digits));
        $i += $digits;
        if (isset($source[$i]) && str_contains(self::WHITESPACE, $source[$i])) {
            $i += substr($source, $i, 2) === "\r\n" ? 2 : 1;
        }
        $character = $codepoint === 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) ? "\u{FFFD}" : mb_chr($codepoint, 'UTF-8');
        return [$character, $i];
    }

    private static function encode(string $url, string $quote): string
    {
        $result = '';
        for ($i = 0, $length = strlen($url); $i < $length; ++$i) {
            $character = $url[$i];
            if (ord($character) < 0x20 || $character === "\x7f") {
                $result .= '\\' . dechex(ord($character)) . ' ';
            } elseif ($character === '\\' || $character === $quote || ($quote === '' && str_contains(" \"'()", $character))) {
                $result .= '\\' . $character;
            } else {
                $result .= $character;
            }
        }
        return $result;
    }
}
