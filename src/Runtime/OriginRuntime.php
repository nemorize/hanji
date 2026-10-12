<?php

declare(strict_types=1);

namespace Hanji\Runtime;

final class OriginRuntime
{
    public static function tag(string $tag, string $source): string
    {
        $context = ContextStack::current();
        return $context->extension === 'html' && $context->origins !== null ? $context->origins->tag($tag, $source) : $tag;
    }
}
