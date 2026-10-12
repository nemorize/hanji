<?php

declare(strict_types=1);

namespace Hanji\Support;

use Hanji\BuildException;

final class Scripts
{
    /**
     * @param array<string, mixed> $scope
     * @return array{mixed, string}
     */
    public static function require(string $path, array $scope = []): array
    {
        $depth = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $value = (static function (string $__hanjiScriptPath, array $__hanjiScope): mixed {
                extract($__hanjiScope, EXTR_SKIP);
                return require $__hanjiScriptPath;
            })($path, $scope);
            while (ob_get_level() > $depth + 1) {
                ob_end_flush();
            }
            if (ob_get_level() !== $depth + 1) {
                throw new BuildException("{$path}: the renderer's output buffer was closed by the template.");
            }
            return [$value, (string) ob_get_contents()];
        } catch (\Throwable $error) {
            throw new BuildException("{$path}:{$error->getLine()}: {$error->getMessage()}", 0, $error, source: $path);
        } finally {
            restore_error_handler();
            while (ob_get_level() > $depth) {
                ob_end_clean();
            }
        }
    }

    public static function original(string $php, string $path): string
    {
        $result = '';
        foreach (\PhpToken::tokenize($php) as $token) {
            $result .= match ($token->id) {
                T_FILE => var_export($path, true),
                T_DIR => var_export(dirname($path), true),
                default => $token->text,
            };
        }
        return $result;
    }
}
