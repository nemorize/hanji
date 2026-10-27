# Hanji

A lightweight static site generator for PHP 8.5, with Blade, PHP and Markdown pages.

## Install

```sh
composer require nemorize/hanji:^0.1
php vendor/bin/hanji init .
php vendor/bin/hanji build
```

The starter creates `hanji.php` and a `site/` directory. Deploy the generated
`_site/` directory to a static host.

```sh
php vendor/bin/hanji serve
php vendor/bin/hanji clean
```

The development server supports CSS updates, live reload and build error overlays.
Native file watching uses PHP FFI when available and falls back to polling.

## Configure

`hanji.php` returns a `Hanji\Config` instance. Paths are relative to its directory.

```php
<?php

use Hanji\Config;

return (new Config())
    ->input('site')
    ->output('_site')
    ->title('My site')
    ->baseUrl('https://example.com')
    ->timezone('Asia/Seoul');
```

Blade (`.blade.php`), PHP (`.php`) and Markdown (`.md`) pages become static HTML.
Other files are copied, including hidden and unreferenced files. Files whose names
start with `@` are reserved for layouts, data, shared templates and other inputs;
they do not produce standalone output.

Use `@layout.blade.php` and `Hanji::children()` for layouts, and `@meta([...])`
in Blade or YAML front matter in Markdown for page metadata. Relative HTML and
CSS asset references follow output mappings and the configured `basePath`.

## Optional SCSS

```sh
composer require scssphp/scssphp:^2.1
```

Register `new Hanji\Plugin\Build\ScssBuildPlugin()` with `Config::plugin()` to
compile SCSS. Without the plugin, SCSS files are copied unchanged.

## CI and updates

Commit your site's `composer.json` and `composer.lock`. A build needs only:

```sh
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php vendor/bin/hanji build
```

Run `composer update nemorize/hanji` to adopt a new compatible release and commit
the updated lock file. No separate Hanji checkout is needed.

## Development and releases

```sh
composer install
composer check
```

Release versions come from Git tags such as `v0.1.0`. Keep `Console::VERSION` in
sync with the release, run the checks, and push the tag. Packagist imports tags
from the registered GitHub repository; do not add a `version` field to
`composer.json`.
