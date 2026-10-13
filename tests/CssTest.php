<?php

declare(strict_types=1);

namespace Hanji\Tests;

use Hanji\Asset\Css;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssTest extends TestCase
{
    public function testCommentsStringsAndNonRelativeUrlsRemainUnchanged(): void
    {
        $css = <<<'CSS'
            /* @import "missing.css"; background: url(missing.svg); */
            @import "https://example.com/theme.css" screen;
            @import url(//cdn.example.com/print.css) print;
            .sample {
                content: "url(missing.svg) and @import 'missing.css'";
                --text: 'url(missing.svg)';
                background: url("data:image/svg+xml,<svg viewBox='0 0 1 1'>(text)</svg>");
                mask: url(#mask);
                cursor: url(/existing.cur), auto;
                border-image: url(https://example.com/border.svg);
                --other: myurl(missing.svg);
                --identifier: url;
            }
            /*# sourceMappingURL=app.css.map */
            CSS;
        $references = [];
        $result = Css::rewriteReferences($css, static function (string $url) use (&$references): string {
            $references[] = $url;
            return $url;
        });
        self::assertSame($css, $result);
        self::assertSame([
            'https://example.com/theme.css', '//cdn.example.com/print.css', "data:image/svg+xml,<svg viewBox='0 0 1 1'>(text)</svg>", '#mask', '/existing.cur', 'https://example.com/border.svg',
        ], $references);
    }

    #[DataProvider('escapedReferences')]
    public function testCssEscapesResolveToTheDecodedUrl(string $css, string $url, string $expected): void
    {
        $references = [];
        $result = Css::rewriteReferences($css, static function (string $reference) use (&$references): string {
            $references[] = $reference;
            return '/docs/image.svg';
        });
        self::assertSame([$url], $references);
        self::assertSame($expected, $result);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function escapedReferences(): iterable
    {
        yield 'escaped space' => ['a { background: url(./a\\ b.svg); }', './a b.svg', 'a { background: url(/docs/image.svg); }'];
        yield 'hex escape' => ['a { background: url("./a\\20 b.svg"); }', './a b.svg', 'a { background: url("/docs/image.svg"); }'];
        yield 'escaped parentheses' => ['a { background: url(./a\\(b\\).svg); }', './a(b).svg', 'a { background: url(/docs/image.svg); }'];
        yield 'unicode escape' => ['a { background: url(./\\d55c \\ae00 .svg); }', './한글.svg', 'a { background: url(/docs/image.svg); }'];
        yield 'escaped function name' => ['a { background: u\\72l( ./image.svg ); }', './image.svg', 'a { background: u\\72l( /docs/image.svg ); }'];
        yield 'escaped import name' => ['@im\\70ort "./theme.css";', './theme.css', '@im\\70ort "/docs/image.svg";'];
        yield 'line continuation' => ["@import './the\\\r\nme.css';", './theme.css', "@import '/docs/image.svg';"];
        yield 'comments around quoted URL' => ['a { background: url("./image.svg" /* image */); }', './image.svg', 'a { background: url("/docs/image.svg" /* image */); }'];
    }

    public function testRewrittenUrlsRemainValidCssWhenTheirSuffixContainsSpecialCharacters(): void
    {
        $css = <<<'CSS'
            a { one: url(./image.svg); two: url('./image.svg'); three: url("./image.svg"); }
            CSS;
        $url = "/docs/image.svg?q=(one) 'two' \"three\"\\four\n";
        $result = Css::rewriteReferences($css, static fn(string $reference): string => $url);
        self::assertSame(<<<'CSS'
            a { one: url(/docs/image.svg?q=\(one\)\ \'two\'\ \"three\"\\four\a ); two: url('/docs/image.svg?q=(one) \'two\' "three"\\four\a '); three: url("/docs/image.svg?q=(one) 'two' \"three\"\\four\a "); }
            CSS, $result);
    }

    public function testMalformedUrlsAreNotResolved(): void
    {
        foreach (['a { background: url(./image.svg extra); }', 'a { background: url("./image.svg" extra); }', 'a { background: url(./image.svg; }', 'a { background: url("./image.svg); }'] as $css) {
            self::assertSame($css, Css::rewriteReferences($css, static function (string $url): never {
                self::fail('Malformed URL tokens must remain untouched.');
            }));
        }
    }
}
