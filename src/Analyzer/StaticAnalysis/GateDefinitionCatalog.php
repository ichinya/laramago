<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Literal Gate declarations from explicitly asserted active source files; never authorization results. */
final class GateDefinitionCatalog
{
    /** @var array<string|int, array{callback: Node\Expr, registration: Node\Stmt\Expression, file: string, line: int}>|null */
    private ?array $definitions = null;
    private bool $complete = false;

    public function __construct(string $root)
    {
        $text = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($options) ? $options['gate-definitions'] ?? null : null;
        if (! is_array($catalog)) {
            return;
        }
        /** @var mixed $files */
        $files = $catalog['files'] ?? null;
        /** @var mixed $complete */
        $complete = array_key_exists('complete', $catalog) ? $catalog['complete'] : false;
        if (! is_array($files) || ! array_is_list($files) || ! is_bool($complete)) {
            return;
        }
        $directory = realpath($root);
        if ($directory === false) {
            return;
        }
        $directory = str_replace('\\', '/', $directory).'/';
        $source = new PhpSource($root);
        $definitions = [];
        /** @var mixed $entry */
        foreach ($files as $entry) {
            $provider = null;
            /** @var mixed $file */
            $file = $entry;
            if (is_array($entry)) {
                /** @var mixed $file */
                $file = $entry['file'] ?? null;
                /** @var mixed $provider */
                $provider = $entry['provider'] ?? null;
                if (! is_string($provider) || $provider === '') {
                    return;
                }
            }
            if (
                ! is_string($file)
                || $file === ''
                || str_contains($file, "\0")
                || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
                || ! str_ends_with($file, '.php')
            ) {
                return;
            }
            $path = realpath($directory.$file);
            if ($path === false || ! str_starts_with(str_replace('\\', '/', $path), $directory) || ! is_file($path)) {
                return;
            }
            $nodes = $source->read($path);
            $declarations = $nodes === null ? null : self::declarations($nodes, $path, $provider);
            if ($declarations === null) {
                return;
            }
            // File order is an explicit effective-map overlay contract.
            $definitions = array_replace($definitions, $declarations);
        }
        $this->definitions = $definitions;
        $this->complete = $complete;
    }

    /** @return array<string|int, array{callback: Node\Expr, registration: Node\Stmt\Expression, file: string, line: int}>|null */
    public function definitions(): ?array
    {
        return $this->definitions;
    }

    /** @return array{callback: Node\Expr, registration: Node\Stmt\Expression, file: string, line: int}|null */
    public function definition(string $ability): ?array
    {
        return $this->definitions[$ability] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->definitions !== null && $this->complete;
    }

    /** Absence concerns explicit definitions only, never whether authorization can succeed. */
    public function contains(string $ability): ?bool
    {
        if ($this->definitions === null) {
            return null;
        }
        if (array_key_exists($ability, $this->definitions)) {
            return true;
        }

        return $this->complete ? false : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return array<string|int, array{callback: Node\Expr, registration: Node\Stmt\Expression, file: string, line: int}>|null
     */
    private static function declarations(array $nodes, string $path, ?string $provider = null): ?array
    {
        $definitions = [];
        if ($provider !== null) {
            $nodes = self::providerBody($nodes, $provider);
            if ($nodes === null) {
                return null;
            }
        }
        foreach ($nodes as $node) {
            if (
                $node instanceof Node\Stmt\Use_
                || $node instanceof Node\Stmt\GroupUse
                || $node instanceof Node\Stmt\Nop
            ) {
                continue;
            }
            if (
                $node instanceof Node\Stmt\Declare_
                && $node->stmts === null
                && count($node->declares) === 1
                && $node->declares[0]->key->toString() === 'strict_types'
                && $node->declares[0]->value instanceof Node\Scalar\Int_
                && $node->declares[0]->value->value === 1
            ) {
                continue;
            }
            if (! $node instanceof Node\Stmt\Expression || ! $node->expr instanceof Node\Expr\StaticCall) {
                return null;
            }
            $call = $node->expr;
            if (
                ! $call->class instanceof Node\Name\FullyQualified
                || strtolower($call->class->toString()) !== 'illuminate\\support\\facades\\gate'
                || ! $call->name instanceof Node\Identifier
                || strtolower($call->name->toString()) !== 'define'
                || count($call->args) !== 2
            ) {
                return null;
            }
            $positions = [];
            foreach ($call->args as $index => $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                    return null;
                }
                $position = $argument->name === null
                    ? $index
                    : match ($argument->name->toString()) {
                        'ability' => 0,
                        'callback' => 1,
                        default => -1,
                    };
                if ($position < 0 || isset($positions[$position])) {
                    return null;
                }
                $positions[$position] = $argument->value;
            }
            $ability = $positions[0] ?? null;
            $callback = $positions[1] ?? null;
            if (! $ability instanceof Node\Scalar\String_ || ! self::literalCallback($callback)) {
                return null;
            }
            if ($callback === null) {
                return null;
            }
            $definitions[$ability->value] = [
                'callback' => $callback,
                'registration' => $node,
                'file' => $path,
                'line' => $call->getStartLine(),
            ];
        }

        return $definitions;
    }

    /** @param array<array-key, Node> $nodes
     * @return array<array-key, Node>|null
     */
    private static function providerBody(array $nodes, string $provider): ?array
    {
        $body = null;
        foreach ($nodes as $node) {
            if (
                $node instanceof Node\Stmt\Declare_
                && $node->stmts === null
                && count($node->declares) === 1
                && $node->declares[0]->key->toString() === 'strict_types'
                && $node->declares[0]->value instanceof Node\Scalar\Int_
                && $node->declares[0]->value->value === 1
            ) {
                continue;
            }
            if ($node instanceof Node\Stmt\Namespace_) {
                if ($body !== null) {
                    return null;
                }
                $body = self::providerBody($node->stmts, $provider);
                if ($body === null) {
                    return null;
                }
                continue;
            }
            if (
                $node instanceof Node\Stmt\Use_
                || $node instanceof Node\Stmt\GroupUse
                || $node instanceof Node\Stmt\Nop
            ) {
                continue;
            }
            if (
                ! $node instanceof Node\Stmt\Class_
                || $body !== null
                || $node->namespacedName?->toString() !== ltrim($provider, '\\')
                || $node->extends?->toString() !== 'Illuminate\\Support\\ServiceProvider'
                || $node->isAbstract()
                || $node->attrGroups !== []
                || count($node->stmts) !== 1
            ) {
                return null;
            }
            $method = $node->stmts[0];
            if (
                ! $method instanceof Node\Stmt\ClassMethod
                || strtolower($method->name->toString()) !== 'boot'
                || ! $method->isPublic()
                || $method->isStatic()
                || $method->params !== []
                || $method->attrGroups !== []
                || $method->stmts === null
            ) {
                return null;
            }
            $body = $method->stmts;
        }

        return $body;
    }

    private static function literalCallback(?Node\Expr $callback): bool
    {
        if (
            $callback instanceof Node\Expr\Closure
            || $callback instanceof Node\Expr\ArrowFunction
            || $callback instanceof Node\Scalar\String_
        ) {
            return true;
        }
        if (! $callback instanceof Node\Expr\Array_ || count($callback->items) !== 2) {
            return false;
        }
        foreach ($callback->items as $item) {
            if ($item->key !== null || $item->byRef || $item->unpack) {
                return false;
            }
        }

        return (
            is_string(PhpSource::value($callback->items[0]->value))
            && $callback->items[1]->value instanceof Node\Scalar\String_
        );
    }
}
