<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\Support\Maps;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

final class Markdown
{
    /** @return array{string, array<string, mixed>} */
    public static function extract(string $source, string $path): array
    {
        $parsed = (new FrontMatterExtension())->getFrontMatterParser()->parse($source);
        return [$parsed->getContent(), Maps::validate($parsed->getFrontMatter() ?? [], $path)];
    }

    public static function render(string $source, bool $allowHtml = false): string
    {
        $environment = new Environment(['html_input' => $allowHtml ? 'allow' : 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        return (string) (new MarkdownConverter($environment))->convert($source);
    }
}
