<?php

declare(strict_types=1);

namespace Hanji;

use Hanji\Support\Paths;
use Hanji\Support\Scripts;

final class ConfigLoader
{
    public function load(string $directory, ?string $file = null): Settings
    {
        $path = Paths::absolute($file ?? 'hanji.php', $directory);
        if (!is_file($path)) {
            if ($file !== null) {
                throw new ConfigurationException("Configuration file does not exist: {$path}");
            }
            return (new Config())->resolve($directory);
        }
        try {
            [$config, $output] = Scripts::require($path);
            if (!$config instanceof Config || $output !== '') {
                throw new ConfigurationException("{$path} must return a Config object without emitting output.");
            }
            return $config->resolve(dirname($path));
        } catch (\Throwable $error) {
            throw new ConfigurationException("{$path}: {$error->getMessage()}", 0, $error);
        }
    }
}
