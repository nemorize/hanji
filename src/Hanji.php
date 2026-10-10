<?php

declare(strict_types=1);

use Hanji\BuildException;
use Hanji\Runtime\ContextStack;
use Hanji\Support\Maps;
use Illuminate\Support\HtmlString;

final class Hanji
{
    /** @param array<string, mixed>|string|null $keyOrValues */
    public static function meta(array|string|null $keyOrValues = null, mixed $default = null): mixed
    {
        $context = ContextStack::current();
        if (is_array($keyOrValues)) {
            if ($context->phase !== 'declaration' || $context->metaDeclared) {
                throw new BuildException("{$context->source}: metadata may be declared once in the leading declaration area.");
            }
            $context->data = Maps::merge($context->data, Maps::validate($keyOrValues, $context->source));
            $context->metaDeclared = true;
            return null;
        }
        return $keyOrValues === null ? $context->data : (array_key_exists($keyOrValues, $context->data) ? $context->data[$keyOrValues] : $default);
    }

    public static function params(?string $key = null, mixed $default = null): mixed
    {
        $params = ContextStack::current()->params;
        return $key === null ? $params : ($params[$key] ?? $default);
    }

    public static function children(): HtmlString
    {
        $context = ContextStack::current();
        return $context->children ?? throw new BuildException("{$context->source}: children() is available only while rendering a layout.");
    }

    public static function extension(string $extension): void
    {
        $context = ContextStack::current();
        if ($context->phase !== 'declaration' || $context->extensionDeclared) {
            throw new BuildException("{$context->source}: extension() may be declared once in the leading declaration area.");
        }
        if (!preg_match('/^[a-z][a-z0-9]*$/D', $extension) || in_array($extension, ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar'], true)) {
            throw new BuildException("{$context->source}: invalid output extension {$extension}.");
        }
        $context->extension = $extension;
        $context->extensionDeclared = true;
    }
}
