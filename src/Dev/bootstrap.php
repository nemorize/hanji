<?php

declare(strict_types=1);

foreach ([dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        return;
    }
}
throw new RuntimeException('Install Hanji dependencies before starting the development server.');
