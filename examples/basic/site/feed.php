<?php

\Hanji::extension('json');

echo json_encode(
    [
        'title' => $site['title'] ?? 'Hanji',
        'posts' => array_map(
            static fn ($post): array => [
                'title' => $post->data['title'],
                'url' => $post->url,
            ],
            $collections['posts'] ?? [],
        ),
    ],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);
