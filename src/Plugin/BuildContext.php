<?php

declare(strict_types=1);

namespace Hanji\Plugin;

use Hanji\Asset\AssetResolver;
use Hanji\BuildException;
use Hanji\Page;
use Hanji\Settings;
use Hanji\Template\Url;

final readonly class BuildContext
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $params
     * @param array<string, mixed> $context
     * @param array<string, list<Page>> $collections
     */
    public function __construct(public Settings $settings, public BuildSession $session, public array $data = [], public array $params = [], public array $context = [], public ?string $body = null, public string $extension = 'html', public ?Page $page = null, public array $collections = [], public ?AssetResolver $assets = null) {}

    public function read(string $path): string
    {
        if ($this->body !== null) {
            return $this->body;
        }
        $content = file_get_contents($path);
        return $content === false ? throw new BuildException("Cannot read {$path}") : $content;
    }

    /** @return array<string, mixed> */
    public function scope(): array
    {
        return ['site' => $this->settings->site, 'page' => $this->page, 'collections' => $this->collections, 'hanji' => new Url($this->settings), ...$this->context];
    }

    public function withOutput(BuildOutput $output): self
    {
        $data = \Hanji\Support\Maps::merge($this->data, $output->data);
        $page = $this->page?->withData($data);
        return new self($this->settings, $this->session, $data, $this->params, \Hanji\Support\Maps::merge($this->context, $output->context), $this->body, $output->extension, $page, $this->collections, $this->assets);
    }
}
