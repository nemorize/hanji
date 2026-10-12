<?php

declare(strict_types=1);

namespace Hanji\Plugin\Transform;

use Hanji\Asset\Html;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\TransformPlugin;

final class RelativePathTransformPlugin implements TransformPlugin
{
    public function supports(string $path, BuildContext $context): bool
    {
        return $context->extension === 'html';
    }

    public function transform(string $path, BuildContext $context, string $content): string
    {
        if ($context->assets === null) {
            return $content;
        }
        foreach (array_reverse(Html::tags($content, false)) as $tag) {
            $source = $context->session->origins->source($content, $tag['start'], $path);
            $text = substr($content, $tag['start'], $tag['end'] - $tag['start']);
            $content = substr_replace($content, $this->tag($text, $source, $context), $tag['start'], $tag['end'] - $tag['start']);
        }
        return $content;
    }

    private function tag(string $tag, string $source, BuildContext $context): string
    {
        preg_match('/^<([a-z][a-z0-9:-]*)/i', $tag, $match);
        $attributes = Html::attributes($tag, false);
        $references = Html::references(strtolower($match[1] ?? ''), $tag);
        uasort($attributes, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($attributes as $name => $attribute) {
            if (!in_array($name, $references, true)) {
                continue;
            }
            $value = html_entity_decode($attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $resolve = static fn(string $url): string => $context->assets?->resolve($url, $source, $context->params) ?? $url;
            $rewritten = $name === 'srcset' ? $this->srcset($value, $resolve) : $resolve($value);
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
    private function srcset(string $value, callable $resolve): string
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
