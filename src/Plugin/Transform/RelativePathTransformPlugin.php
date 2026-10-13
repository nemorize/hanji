<?php

declare(strict_types=1);

namespace Hanji\Plugin\Transform;

use Hanji\Asset\Css;
use Hanji\Asset\Html;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\TransformPlugin;

final class RelativePathTransformPlugin implements TransformPlugin
{
    public function supports(string $path, BuildContext $context): bool
    {
        return in_array($context->extension, ['html', 'css'], true);
    }

    public function transform(string $path, BuildContext $context, string $content): string
    {
        if ($context->assets === null) {
            return $content;
        }
        if ($context->extension === 'css') {
            return Css::rewriteReferences($content, static fn(string $url): string => $context->assets->resolve($url, $path, $context->params));
        }
        return Html::rewriteReferences(
            $content,
            static function (string $url, int $offset, bool $required) use ($path, $context, $content): string {
                $source = $context->session->origins->source($content, $offset, $path);
                return $required ? $context->assets->resolve($url, $source, $context->params) : $context->assets->resolveKnown($url, $source, $context->params);
            },
        );
    }
}
