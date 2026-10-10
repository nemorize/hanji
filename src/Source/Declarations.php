<?php

declare(strict_types=1);

namespace Hanji\Source;

use Hanji\BuildException;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class Declarations
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    public function extract(string $source, string $kind): Declaration
    {
        $position = 0;
        $parts = [];
        $imports = [];
        while (true) {
            $start = $position + strspn($source, " \t\r\n", $position);
            if ($kind === 'blade' && substr($source, $start, 4) === '{{--') {
                $end = strpos($source, '--}}', $start + 4);
                if ($end === false) {
                    throw new BuildException('Unclosed leading Blade comment.');
                }
                $position = $end + 4;
                continue;
            }
            if ($kind === 'blade' && substr($source, $start, 5) === '@meta') {
                $open = $start + 5 + strspn($source, " \t\r\n", $start + 5);
                if (($source[$open] ?? '') !== '(') {
                    break;
                }
                $end = self::parenthesis($source, $open);
                $parts[] = '\\Hanji::meta' . substr($source, $open, $end - $open) . ';';
                $position = $end;
                continue;
            }
            $blade = $kind === 'blade' && substr($source, $start, 4) === '@php' && !preg_match('/\w/', $source[$start + 4] ?? '');
            $native = substr($source, $start, 5) === '<?php';
            if (!$blade && !$native) {
                break;
            }
            $codeStart = $start + ($blade ? 4 : 5);
            $closing = $blade ? $this->endBlade($source, $codeStart) : $this->endPhp($source, $start);
            if ($closing === null) {
                if ($native) {
                    $plain = $this->plain(substr($source, $start), $imports);
                    $parts[] = $plain->php;
                    $body = $this->prefix($source, $start, $imports) . $plain->body;
                    return new Declaration($body, implode("\n", $parts));
                }
                throw new BuildException('Unclosed leading @php block.');
            }
            [$codeEnd, $end] = $closing;
            $code = substr($source, $codeStart, $codeEnd - $codeStart);
            $nodes = $this->parse('<?php ' . $code, $imports);
            if ($native && $this->directWithBody($nodes)) {
                $plain = $this->plain(substr($source, $start, $end - $start), $imports);
                $parts[] = $plain->php;
                return new Declaration($this->prefix($source, $start, $imports) . $plain->body . substr($source, $end), implode("\n", $parts));
            }
            $onlyImports = array_all($nodes, static fn(Stmt $node): bool => $node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse || $node instanceof Stmt\Declare_ || $node instanceof Stmt\Nop);
            if (!$this->containsDeclaration($nodes) && !$onlyImports) {
                break;
            }
            foreach ($nodes as $node) {
                if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse || $node instanceof Stmt\Declare_) {
                    $imports[] = substr('<?php ' . $code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
                }
            }
            $parts[] = $code;
            $position = $end;
        }
        if ($parts === []) {
            return new Declaration($source, '');
        }
        $body = ltrim(substr($source, $position), " \t\r\n");
        $prefix = $this->prefix($source, strlen($source) - strlen($body), $imports);
        return new Declaration($prefix . $body, implode("\n", $parts));
    }

    public function validateScope(string $php): void
    {
        $this->validateVariables($this->parse('<?php ' . $php));
    }

    /** @param list<Node> $nodes */
    private function validateVariables(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Function_ || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                continue;
            }
            if ($node instanceof Node\Expr\Variable && is_string($node->name) && in_array($node->name, ['page', 'collections', 'hanji'], true)) {
                throw new BuildException('Page URLs, collections and rendering helpers are unavailable while collecting declarations.');
            }
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->$name;
                if ($value instanceof Node) {
                    $this->validateVariables([$value]);
                } elseif (is_array($value)) {
                    foreach ($value as $child) {
                        if ($child instanceof Node) {
                            $this->validateVariables([$child]);
                        }
                    }
                }
            }
        }
    }

    /** @param list<string> $imports */
    private function prefix(string $source, int $consumed, array $imports): string
    {
        return $consumed === 0 ? '' : '<?php ' . implode("\n", $imports) . ' /*' . str_repeat("\n", substr_count(substr($source, 0, $consumed), "\n")) . '*/ ?>';
    }

    /** @param list<Stmt> $nodes */
    private function directWithBody(array $nodes): bool
    {
        $found = false;
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse || $node instanceof Stmt\Declare_ || $node instanceof Stmt\Nop) {
                continue;
            }
            if ($node instanceof Stmt\Expression && $this->isDeclaration($node->expr)) {
                $found = true;
            } else {
                return $found;
            }
        }
        return false;
    }

    /** @param list<string> $imports */
    private function plain(string $source, array $imports = []): Declaration
    {
        $nodes = $this->parse($source, $imports);
        $parts = [];
        $body = $source;
        $found = false;
        foreach ($nodes as $node) {
            $import = $node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse || $node instanceof Stmt\Declare_;
            if (!$import && !($node instanceof Stmt\Expression && $this->isDeclaration($node->expr))) {
                break;
            }
            $text = substr($source, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
            $parts[] = $text;
            if (!$import) {
                $found = true;
                $body = substr_replace($body, preg_replace('/[^\r\n]/', ' ', $text) ?? '', $node->getStartFilePos(), strlen($text));
            }
        }
        return new Declaration($body, $found ? implode("\n", $parts) : '');
    }

    /**
     * @param list<string> $imports
     * @return list<Stmt>
     */
    private function parse(string $source, array $imports = []): array
    {
        $prefix = $imports === [] ? '' : '<?php ' . implode("\n", $imports) . "\n";
        $nodes = $this->parser->parse($prefix === '' ? $source : $prefix . substr($source, 5)) ?? [];
        $traverser = new NodeTraverser(new NameResolver());
        /** @var list<Stmt> $resolved */
        $resolved = $traverser->traverse($nodes);
        if ($prefix === '') {
            return $resolved;
        }
        $result = [];
        foreach ($resolved as $node) {
            if ($node->getStartFilePos() >= strlen($prefix)) {
                $node->setAttribute('startFilePos', $node->getStartFilePos() - strlen($prefix) + 5);
                $node->setAttribute('endFilePos', $node->getEndFilePos() - strlen($prefix) + 5);
                $result[] = $node;
            }
        }
        return $result;
    }

    /** @param list<Node> $nodes */
    private function containsDeclaration(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($this->isDeclaration($node)) {
                return true;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                if ($child instanceof Node && $this->containsDeclaration([$child])) {
                    return true;
                }
                if (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node && $this->containsDeclaration([$item])) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    private function isDeclaration(Node $node): bool
    {
        if (!$node instanceof StaticCall || !$node->class instanceof Node\Name || strcasecmp($node->class->toString(), 'Hanji') !== 0 || !$node->name instanceof Node\Identifier) {
            return false;
        }
        $name = strtolower($node->name->toString());
        if ($name === 'extension') {
            return true;
        }
        $argument = $node->args[0] ?? null;
        return $name === 'meta' && $argument instanceof Node\Arg && !$argument->value instanceof Node\Scalar\String_ && !$argument->value instanceof Node\Expr\ConstFetch;
    }

    /** @return ?array{int, int} */
    private function endPhp(string $source, int $start): ?array
    {
        foreach (\PhpToken::tokenize(substr($source, $start)) as $token) {
            if ($token->id === T_CLOSE_TAG) {
                return [$start + $token->pos, $start + $token->pos + strlen($token->text)];
            }
        }
        return null;
    }

    /** @return ?array{int, int} */
    private function endBlade(string $source, int $start): ?array
    {
        $tokens = \PhpToken::tokenize('<?php ' . substr($source, $start));
        foreach ($tokens as $i => $token) {
            if ($token->text === '@' && ($tokens[$i + 1]->text ?? '') === 'endphp') {
                $position = $start + $token->pos - 6;
                return [$position, $position + 7];
            }
        }
        return null;
    }

    public static function parenthesis(string $source, int $start): int
    {
        $depth = 0;
        foreach (\PhpToken::tokenize('<?php ' . substr($source, $start)) as $token) {
            if ($token->text === '(') {
                ++$depth;
            } elseif ($token->text === ')' && --$depth === 0) {
                return $start + $token->pos - 6 + 1;
            }
        }
        throw new BuildException('Unclosed directive expression.');
    }
}
