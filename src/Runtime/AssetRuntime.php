<?php

declare(strict_types=1);

namespace Hanji\Runtime;

use Hanji\Asset\Html;

final class AssetRuntime
{
    public static function html(string $html, string $source): string
    {
        foreach (array_reverse(Html::tags($html)) as $tag) {
            $text = substr($html, $tag['start'], $tag['end'] - $tag['start']);
            $html = substr_replace($html, self::tag($text, $source), $tag['start'], $tag['end'] - $tag['start']);
        }
        return $html;
    }

    public static function tag(string $tag, string $source): string
    {
        $context = ContextStack::current();
        if ($context->extension !== 'html' || $context->assets === null) {
            return $tag;
        }
        preg_match('/^<([a-z][a-z0-9:-]*)/i', $tag, $match);
        $attributes = Html::attributes($tag);
        $references = Html::references(strtolower($match[1] ?? ''), $tag);
        uasort($attributes, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($attributes as $name => $attribute) {
            if (!in_array($name, $references, true)) {
                continue;
            }
            $value = html_entity_decode($attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $resolve = static fn(string $url): string => $context->assets->resolve($url, $source, $context->params);
            $rewritten = $name === 'srcset' ? self::srcset($value, $resolve) : $resolve($value);
            if ($rewritten === $value) {
                continue;
            }
            $rewritten = htmlspecialchars($rewritten, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            if ($attribute['quote'] === '') {
                $rewritten = '"' . $rewritten . '"';
            }
            $tag = substr_replace($tag, $rewritten, $attribute['start'], $attribute['length']);
        }
        return $tag;
    }

    /** @param callable(string): string $resolve */
    private static function srcset(string $value, callable $resolve): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length;) {
            $start = $i;
            $i += strspn($value, " \t\r\n,", $i);
            $result .= substr($value, $start, $i - $start);
            if ($i >= $length) {
                break;
            }
            $start = $i;
            $data = strncasecmp(substr($value, $i, 5), 'data:', 5) === 0;
            $i += strcspn($value, $data ? " \t\r\n" : " \t\r\n,", $i);
            $url = substr($value, $start, $i - $start);
            $result .= $resolve($url);
            $start = $i;
            $i += strcspn($value, ',', $i);
            $result .= substr($value, $start, $i - $start);
        }
        return $result;
    }
}
