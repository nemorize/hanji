<?php

declare(strict_types=1);

namespace Hanji\Plugin\Build;

use Hanji\Plugin\BuildContext;
use Hanji\Plugin\BuildOutput;
use Hanji\Plugin\BuildPreparation;
use Hanji\Plugin\PreparesBuild;
use Hanji\Support\Maps;
use Hanji\Template\Markdown as Converter;

final class MarkdownBuildPlugin implements PreparesBuild
{
    public function supports(string $path): bool
    {
        return str_ends_with($path, '.md');
    }

    public function prepare(string $path, BuildContext $context): BuildPreparation
    {
        [$body, $data] = Converter::extract($context->read($path), $path);
        $sidecar = dirname($path) . '/@' . basename($path, '.md') . '.data';
        return new BuildPreparation(body: $body, data: Maps::merge($context->session->data->sidecar($sidecar), $data));
    }

    public function build(string $path, BuildContext $context): BuildOutput
    {
        return new BuildOutput(Converter::render($context->read($path), $context->settings->markdownHtml));
    }
}
