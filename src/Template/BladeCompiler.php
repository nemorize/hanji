<?php

declare(strict_types=1);

namespace Hanji\Template;

use Hanji\Asset\Html;
use Hanji\Support\Scripts;

final class BladeCompiler extends \Illuminate\View\Compilers\BladeCompiler
{
    /** @var array<string, string> */
    public array $origins = [];
    /** @var array<string, string> */
    private array $compiledOrigins = [];

    public function origin(string $compiledPath): string
    {
        return $this->compiledOrigins[$compiledPath] ?? $compiledPath;
    }

    /** @param string $value */
    protected function compileComponentTags($value): string
    {
        // The upstream closing slot directive can run into following plain text.
        // Compile that generated directive immediately so inline slots stay balanced.
        $result = '';
        foreach (\PhpToken::tokenize(parent::compileComponentTags($value)) as $token) {
            $result .= $token->id === T_INLINE_HTML ? str_replace('@endslot', $this->compileEndSlot(), $token->text) : $token->text;
        }
        return $result;
    }

    /** @param string $value */
    public function compileString($value): string
    {
        $origin = $this->origins[$this->getPath()] ?? $this->getPath();
        $this->compiledOrigins[$this->getCompiledPath($this->getPath())] = $origin;
        return Scripts::original(parent::compileString(Html::trackOrigins($value, $origin)), $origin);
    }
}
