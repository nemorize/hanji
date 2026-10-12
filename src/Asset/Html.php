<?php

declare(strict_types=1);

namespace Hanji\Asset;

use Hanji\Source\Declarations;

/** A small lexical scanner: only tag/attribute bytes change, never text or PHP strings. */
final class Html
{
    /** @return list<array{start: int, end: int, name: string}> */
    public static function tags(string $source, bool $template = true): array
    {
        $tags = [];
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            $skip = self::expressionEnd($source, $i, $template);
            if ($skip !== null) {
                $i = $skip;
                continue;
            }
            if ($template && substr($source, $i, 9) === '@verbatim') {
                $end = strpos($source, '@endverbatim', $i + 9);
                $i = $end === false ? $length : $end + 12;
                continue;
            }
            if (substr($source, $i, 4) === '<!--') {
                $end = strpos($source, '-->', $i + 4);
                $i = $end === false ? $length : $end + 3;
                continue;
            }
            if ($source[$i] !== '<' || !preg_match('/\G<([a-z][a-z0-9:-]*)\b/i', $source, $match, 0, $i)) {
                ++$i;
                continue;
            }
            $name = strtolower($match[1]);
            $end = self::tagEnd($source, $i + strlen($match[0]), $template);
            if ($end === null) {
                break;
            }
            $tags[] = ['start' => $i, 'end' => $end, 'name' => $name];
            $i = $end;
            if (in_array($name, ['script', 'style', 'textarea', 'pre', 'code'], true)) {
                $close = stripos($source, '</' . $name, $i);
                $i = $close === false ? $length : $close;
            }
        }
        return $tags;
    }

    /** @return array<string, array{start: int, length: int, value: string, quote: string}> */
    public static function attributes(string $tag, bool $template = true): array
    {
        preg_match('/^<[a-z][a-z0-9:-]*/i', $tag, $match);
        $i = strlen($match[0] ?? '');
        $length = strlen($tag);
        $attributes = [];
        while ($i < $length) {
            $i += strspn($tag, " \t\r\n/", $i);
            if ($i >= $length || $tag[$i] === '>') {
                break;
            }
            $start = $i;
            $i += strcspn($tag, " \t\r\n=/>", $i);
            if ($i === $start) {
                ++$i;
                continue;
            }
            $name = strtolower(substr($tag, $start, $i - $start));
            $i += strspn($tag, " \t\r\n", $i);
            if (($tag[$i] ?? '') !== '=') {
                continue;
            }
            ++$i;
            $i += strspn($tag, " \t\r\n", $i);
            $quote = in_array($tag[$i] ?? '', ['"', "'"], true) ? $tag[$i++] : '';
            $valueStart = $i;
            while ($i < $length) {
                $skip = $template ? self::expressionEnd($tag, $i) : null;
                if ($skip !== null) {
                    $i = $skip;
                } elseif ($quote !== '' ? $tag[$i] === $quote : str_contains(" \t\r\n>", $tag[$i])) {
                    break;
                } else {
                    ++$i;
                }
            }
            $attributes[$name] = ['start' => $valueStart, 'length' => $i - $valueStart, 'value' => substr($tag, $valueStart, $i - $valueStart), 'quote' => $quote];
            if ($quote !== '' && $i < $length) {
                ++$i;
            }
        }
        return $attributes;
    }

    /** @return list<string> */
    public static function references(string $name, string $tag): array
    {
        return match ($name) {
            'img', 'source' => ['src', 'srcset'],
            'script', 'audio' => ['src'],
            'video' => ['src', 'poster'],
            'link' => preg_match('/(?:^|\s)(?:stylesheet|icon)(?:\s|$)/i', self::attributes($tag, false)['rel']['value'] ?? '') ? ['href'] : [],
            default => [],
        };
    }

    public static function trackOrigins(string $source, string $origin): string
    {
        foreach (array_reverse(self::tags($source)) as $tag) {
            $text = substr($source, $tag['start'], $tag['end'] - $tag['start']);
            if (str_starts_with($tag['name'], 'x-')) {
                continue;
            }
            $before = '<?php ob_start(); ?>';
            $call = 'echo \\Hanji\\Runtime\\OriginRuntime::tag((string) ob_get_clean(), ' . var_export($origin, true) . ');';
            $newline = str_starts_with(substr($source, $tag['end']), "\r\n") ? "\r\n" : (str_starts_with(substr($source, $tag['end']), "\n") ? "\n" : '');
            $after = '<?php ' . $call . ($newline === '' ? '' : ' echo ' . var_export($newline, true) . ';') . ' ?>';
            $source = substr_replace($source, $before . $text . $after, $tag['start'], $tag['end'] - $tag['start'] + strlen($newline));
        }
        return $source;
    }

    private static function tagEnd(string $source, int $start, bool $template): ?int
    {
        $quote = '';
        for ($i = $start, $length = strlen($source); $i < $length; ++$i) {
            $skip = $template ? self::expressionEnd($source, $i) : null;
            if ($skip !== null) {
                $i = $skip - 1;
            } elseif ($quote !== '') {
                if ($source[$i] === $quote) {
                    $quote = '';
                }
            } elseif ($source[$i] === '"' || $source[$i] === "'") {
                $quote = $source[$i];
            } elseif ($source[$i] === '>') {
                return $i + 1;
            }
        }
        return null;
    }

    private static function expressionEnd(string $source, int $position, bool $template = true): ?int
    {
        if (substr($source, $position, 4) === '{{--') {
            $end = strpos($source, '--}}', $position + 4);
            return $end === false ? ($template ? strlen($source) : null) : $end + 4;
        }
        $native = substr($source, $position, 5) === '<?php' || substr($source, $position, 3) === '<?=';
        $blade = substr($source, $position, 4) === '@php' && !preg_match('/\w/', $source[$position + 4] ?? '');
        if ($native || $blade) {
            $start = $position + ($blade ? 4 : 0);
            if ($blade && ($source[$start] ?? '') === '(') {
                return Declarations::parenthesis($source, $start);
            }
            $tokens = \PhpToken::tokenize(($blade ? '<?php ' : '') . substr($source, $start));
            foreach ($tokens as $i => $token) {
                if (!$blade && $token->id === T_CLOSE_TAG) {
                    return $start + $token->pos + strlen($token->text);
                }
                if ($blade && $token->text === '@' && ($tokens[$i + 1]->text ?? '') === 'endphp') {
                    return $start + $token->pos - 6 + 7;
                }
            }
            return $template ? strlen($source) : null;
        }
        $open = substr($source, $position, 3) === '{!!' ? 3 : (substr($source, $position, 2) === '{{' ? 2 : 0);
        if ($open !== 0) {
            $tokens = \PhpToken::tokenize('<?php ' . substr($source, $position + $open));
            $depth = 0;
            foreach ($tokens as $i => $token) {
                if ($depth === 0 && (($open === 2 && $token->text === '}' && ($tokens[$i + 1]->text ?? '') === '}') || ($open === 3 && $token->text === '!' && ($tokens[$i + 1]->text ?? '') === '!' && ($tokens[$i + 2]->text ?? '') === '}'))) {
                    return $position + $open + $token->pos - 6 + $open;
                }
                if (in_array($token->text, ['(', '[', '{'], true)) {
                    ++$depth;
                } elseif (in_array($token->text, [')', ']', '}'], true)) {
                    --$depth;
                }
            }
            return $template ? strlen($source) : null;
        }
        return null;
    }
}
