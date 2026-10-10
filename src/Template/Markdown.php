<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\Runtime\ContextStack;
use Hanji\Runtime\AssetRuntime;
use Hanji\Support\Maps;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
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

    public static function render(string $source, string $origin, bool $allowHtml = false): string
    {
        $environment = new Environment(['html_input' => $allowHtml ? 'allow' : 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event) use ($origin, $allowHtml): void {
            $context = ContextStack::current();
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Image && $context->assets !== null) {
                    $node->setUrl($context->assets->resolve($node->getUrl(), $origin, $context->params));
                }
                if ($allowHtml && ($node instanceof HtmlInline || $node instanceof HtmlBlock)) {
                    $node->setLiteral(AssetRuntime::html($node->getLiteral(), $origin));
                }
            }
        });
        return (string) (new MarkdownConverter($environment))->convert($source);
    }
}
