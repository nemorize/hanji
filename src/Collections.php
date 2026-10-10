<?php

declare(strict_types=1);

namespace Hanji;

final class Collections
{
    /**
     * @param list<Page> $pages
     * @return array<string, list<Page>>
     */
    public static function create(array $pages): array
    {
        usort($pages, static function (Page $a, Page $b): int {
            $date = ($b->date?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->date?->getTimestamp() ?? PHP_INT_MIN);
            return $date ?: strcmp($a->inputPath, $b->inputPath) ?: strcmp($a->routePath, $b->routePath);
        });
        $collections = ['all' => $pages];
        foreach ($pages as $page) {
            /** @var list<string> $tags */
            $tags = $page->data['tags'] ?? [];
            foreach ($tags as $tag) {
                $collections[$tag][] = $page;
            }
        }
        return $collections;
    }
}
