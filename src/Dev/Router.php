<?php

declare(strict_types=1);

namespace Hanji\Dev;

use Hanji\Asset\Html;
use Hanji\Support\Paths;

final readonly class Router
{
    public const CLIENT = '/__hanji/client.js';
    public const STATUS = '/__hanji/status';
    public const SOCKET = '/__hanji/ws';

    private const TYPES = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8', 'txt' => 'text/plain; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'pdf' => 'application/pdf', 'wasm' => 'application/wasm', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];

    /** @param array<string, mixed> $state */
    public function __construct(private array $state) {}

    public function handle(string $uri, string $method = 'GET', string $host = 'localhost'): Response
    {
        if (!$this->allowedHost($host)) {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'This host is not allowed.');
        }
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return new Response(405, ['Allow' => 'GET, HEAD', 'Content-Type' => 'text/plain; charset=utf-8'], 'Method not allowed.');
        }
        $encoded = parse_url($uri, PHP_URL_PATH);
        if (!is_string($encoded) || !str_starts_with($encoded, '/') || str_starts_with($encoded, '//') || preg_match('/%(?:2f|5c)|%(?![0-9a-f]{2})/i', $encoded)) {
            return new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Invalid request path.');
        }
        $path = rawurldecode($encoded);
        if (preg_match('/[\\\\\x00-\x1f\x7f]|(?:^|\/)\.\.?($|\/)/', $path)) {
            return new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'Invalid request path.');
        }
        if ($path === self::CLIENT) {
            return new Response(headers: ['Content-Type' => self::TYPES['js']], file: __DIR__ . '/client.js');
        }
        if ($path === self::STATUS) {
            $data = self::publicState($this->state);
            return new Response(headers: ['Content-Type' => self::TYPES['json']], body: json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $project = Project::decode($this->state['project'] ?? null);
        if (($this->state['published'] ?? false) !== true) {
            return $this->missing();
        }
        if ($project->basePath !== '/' && ($path === '/' || $path === rtrim($project->basePath, '/'))) {
            $query = parse_url($uri, PHP_URL_QUERY);
            return new Response(302, ['Location' => $project->url('/') . (is_string($query) ? '?' . $query : '')]);
        }
        if (!str_starts_with($path, $project->basePath)) {
            return $this->missing();
        }
        $relative = substr($path, strlen($project->basePath));
        $file = Paths::absolute($relative === '' ? 'index.html' : $relative, $project->output);
        if (!Paths::within($file, $project->output)) {
            return $this->missing();
        }
        if (is_dir($file)) {
            if (!str_ends_with($path, '/')) {
                $query = parse_url($uri, PHP_URL_QUERY);
                return new Response(301, ['Location' => Paths::encode($path) . '/' . (is_string($query) ? '?' . $query : '')]);
            }
            $file = Paths::absolute($file . '/index.html', $project->output);
        }
        if (!Paths::within($file, $project->output) || !is_file($file)) {
            return $this->missing();
        }
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        if (str_starts_with($type, 'text/html')) {
            $html = file_get_contents($file);
            return $html === false ? $this->missing() : new Response(headers: ['Content-Type' => $type], body: $this->inject($html));
        }
        return new Response(headers: ['Content-Type' => $type], file: $file);
    }

    private function inject(string $html): string
    {
        $attributes = '';
        foreach (['session', 'revision', 'basePath'] as $name) {
            $value = $this->state[$name] ?? '';
            if (is_string($value) || is_int($value)) {
                $attribute = $name === 'basePath' ? 'base-path' : $name;
                $attributes .= ' data-' . $attribute . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
            }
        }
        $script = '<script defer src="' . self::CLIENT . '"' . $attributes . '></script>';
        foreach (Html::tags($html, false) as $tag) {
            if ($tag['name'] === 'head' || $tag['name'] === 'body') {
                return substr_replace($html, $script, $tag['end'], 0);
            }
        }
        return $script . $html;
    }

    private function missing(): Response
    {
        return new Response(404, ['Content-Type' => self::TYPES['html']], $this->inject('<!doctype html><html><head><title>Page not found · Hanji</title></head><body><h1>404 · Page not found</h1></body></html>'));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function publicState(array $state): array
    {
        return array_intersect_key($state, array_flip(['session', 'revision', 'status', 'error', 'changes', 'basePath']));
    }

    public function allowedHost(string $header): bool
    {
        $host = parse_url('http://' . $header, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        $host = strtolower(trim($host, '[]'));
        $bind = $this->state['host'] ?? '127.0.0.1';
        if (in_array($host, ['localhost', '127.0.0.1', '::1', $bind], true)) {
            return true;
        }
        return in_array($bind, ['0.0.0.0', '::'], true) && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
