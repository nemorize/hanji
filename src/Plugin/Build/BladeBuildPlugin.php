<?php

declare(strict_types=1);

namespace Hanji\Plugin\Build;

use Hanji\Plugin\BuildContext;
use Hanji\Plugin\BuildOutput;
use Hanji\Plugin\BuildPreparation;
use Hanji\Plugin\LayoutPlugin;
use Hanji\Plugin\PreparesBuild;
use Hanji\Runtime\Context;
use Hanji\Runtime\ContextStack;
use Illuminate\Support\HtmlString;

final class BladeBuildPlugin implements PreparesBuild, LayoutPlugin
{
    public function supports(string $path): bool
    {
        return str_ends_with($path, '.blade.php');
    }

    public function prepare(string $path, BuildContext $context): BuildPreparation
    {
        return PhpBuildPlugin::declarations($path, $context, 'blade');
    }

    public function build(string $path, BuildContext $context): BuildOutput
    {
        return new BuildOutput($context->session->blade()->render($path, $context->read($path), $context->scope()), $context->extension);
    }

    public function layout(string $path, BuildContext $context, BuildOutput $output): BuildOutput
    {
        $content = $output->content;
        $directory = dirname($path);
        while (true) {
            $layout = $directory . '/@layout.blade.php';
            if (is_file($layout)) {
                $runtime = new Context('layout', $layout, $context->data, $context->params, 'html', new HtmlString($content), $context->session->origins);
                $content = ContextStack::run($runtime, fn(): string => $context->session->blade()->render($layout, (string) file_get_contents($layout), $context->scope()));
            }
            if ($directory === $context->settings->input) {
                break;
            }
            $directory = dirname($directory);
        }
        return new BuildOutput($content, $output->extension);
    }
}
