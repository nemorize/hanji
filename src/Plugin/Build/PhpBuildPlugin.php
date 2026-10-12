<?php

declare(strict_types=1);

namespace Hanji\Plugin\Build;

use Hanji\Asset\Html;
use Hanji\BuildException;
use Hanji\Plugin\BuildContext;
use Hanji\Plugin\BuildOutput;
use Hanji\Plugin\BuildPreparation;
use Hanji\Plugin\PreparesBuild;
use Hanji\Runtime\ContextStack;
use Hanji\Source\Declarations;
use Hanji\Support\Scripts;
use Symfony\Component\Filesystem\Filesystem;

final class PhpBuildPlugin implements PreparesBuild
{
    public function supports(string $path): bool
    {
        return str_ends_with($path, '.php') && !str_ends_with($path, '.blade.php');
    }

    public function prepare(string $path, BuildContext $context): BuildPreparation
    {
        return self::declarations($path, $context, 'php');
    }

    public static function declarations(string $path, BuildContext $context, string $kind): BuildPreparation
    {
        $declarations = new Declarations();
        $declaration = $declarations->extract($context->read($path), $kind);
        $runtime = ContextStack::current();
        if ($declaration->php !== '') {
            $declarations->validateScope($declaration->php);
            $script = $context->settings->cache . '/declarations/' . hash('sha256', $path . $declaration->php) . '.php';
            (new Filesystem())->dumpFile($script, Scripts::original('<?php ' . $declaration->php, $path));
            [, $output] = Scripts::require($script, ['site' => $context->settings->site, ...$context->context]);
            if ($output !== '') {
                throw new BuildException('Leading declarations must not emit output.');
            }
        }
        return new BuildPreparation($runtime->extension, $declaration->body, data: $runtime->data, sourceExtension: $kind === 'blade' ? 'blade.php' : 'php');
    }

    public function build(string $path, BuildContext $context): BuildOutput
    {
        $body = $context->read($path);
        $script = $context->settings->cache . '/scripts/' . hash('sha256', $path . $body) . '.php';
        (new Filesystem())->dumpFile($script, Scripts::original($context->extension === 'html' ? Html::trackOrigins($body, $path) : $body, $path));
        return new BuildOutput(Scripts::require($script, $context->scope())[1], $context->extension);
    }
}
