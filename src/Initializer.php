<?php

declare(strict_types=1);

namespace Hanji;

use Symfony\Component\Filesystem\Filesystem;

final class Initializer
{
    public function initialize(string $directory): void
    {
        $files = [
            'hanji.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                use Hanji\Config;

                return (new Config())
                    ->input('site')
                    ->output('_site')
                    ->title('Hanji');
                PHP,
            'site/@layout.blade.php' => <<<'BLADE'
                <!doctype html>
                <html lang="ko">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>{{ Hanji::meta('title', $site['title']) }}</title>
                    <link rel="stylesheet" href="./assets/app.css">
                </head>
                <body>{{ Hanji::children() }}</body>
                </html>
                BLADE,
            'site/index.blade.php' => <<<'BLADE'
                @meta(['title' => 'Hello, Hanji!', 'tags' => ['pages']])
                <main>
                    <h1>{{ Hanji::meta('title') }}</h1>
                    <x-card><p>Your first static page, rendered with PHP and Blade.</p></x-card>
                </main>
                BLADE,
            'site/_includes/components/@card.blade.php' => '<section>{{ $slot }}</section>',
            'site/assets/app.css' => <<<'CSS'
                body { margin: 0; background: #faf7ef; color: #29241f; font-family: system-ui, sans-serif; }
                main { max-width: 60rem; margin: 4rem auto; padding: 0 1rem; }
                CSS,
        ];
        foreach (array_keys($files) as $relative) {
            if (file_exists($directory . '/' . $relative)) {
                throw new ConfigurationException("Initialization would overwrite {$relative}.");
            }
            $parent = dirname($directory . '/' . $relative);
            while ($parent !== dirname($parent)) {
                if (file_exists($parent) && !is_dir($parent)) {
                    throw new ConfigurationException("Initialization requires directory {$parent}.");
                }
                $parent = dirname($parent);
            }
        }
        $filesystem = new Filesystem();
        foreach ($files as $relative => $content) {
            $filesystem->dumpFile($directory . '/' . $relative, $content . "\n");
        }
    }
}
