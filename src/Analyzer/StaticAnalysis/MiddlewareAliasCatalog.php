<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Effective alias declarations from explicitly asserted active application sources. */
final class MiddlewareAliasCatalog
{
    /** @var array<string, string>|null */
    private ?array $aliases = null;
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
        $catalog = is_array($options) ? $options['middleware-aliases'] ?? null : null;
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
        $aliases = [];
        /** @var mixed $file */
        foreach ($files as $file) {
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
            $declarations = $nodes === null ? null : self::declarations($nodes);
            if ($declarations === null) {
                return;
            }
            // File order is an explicit effective-map overlay contract.
            $aliases = array_replace($aliases, $declarations);
        }
        $this->aliases = $aliases;
        $this->complete = $complete;
    }

    /** @return array<string, string>|null Null means absent or unsupported sources. */
    public function aliases(): ?array
    {
        return $this->aliases;
    }

    public function target(string $alias): ?string
    {
        return $this->aliases[$alias] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->aliases !== null && $this->complete;
    }

    /** Exact alias lookup, not middleware/group/class resolution or parameter splitting. */
    public function contains(string $alias): ?bool
    {
        if ($this->aliases === null) {
            return null;
        }
        if (array_key_exists($alias, $this->aliases)) {
            return true;
        }

        return $this->complete ? false : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return array<string, string>|null
     */
    private static function declarations(array $nodes): ?array
    {
        $returned = null;
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
            if (! $node instanceof Node\Stmt\Return_ || $returned !== null || $node->expr === null) {
                return null;
            }
            $returned = $node->expr;
        }
        if ($returned instanceof Node\Expr\Array_) {
            return self::literalMap($returned);
        }
        $callback = null;
        while ($returned instanceof Node\Expr\MethodCall) {
            if (! $returned->name instanceof Node\Identifier) {
                return null;
            }
            if (strtolower($returned->name->toString()) === 'withmiddleware') {
                if ($callback !== null || count($returned->args) !== 1) {
                    return null;
                }
                $callback = PhpSource::argument($returned->args, 0, 'callback');
                if (! $callback instanceof Node\Expr\Closure) {
                    return null;
                }
            }
            $returned = $returned->var;
        }
        if (
            ! $returned instanceof Node\Expr\StaticCall
            || ! $returned->class instanceof Node\Name\FullyQualified
            || strtolower($returned->class->toString()) !== 'illuminate\\foundation\\application'
            || ! $returned->name instanceof Node\Identifier
            || strtolower($returned->name->toString()) !== 'configure'
            || ! $callback instanceof Node\Expr\Closure
            || $callback->uses !== []
            || count($callback->params) !== 1
        ) {
            return null;
        }
        $parameter = $callback->params[0];
        if (
            ! $parameter->type instanceof Node\Name\FullyQualified
            || strtolower($parameter->type->toString()) !== 'illuminate\\foundation\\configuration\\middleware'
            || ! $parameter->var instanceof Node\Expr\Variable
            || ! is_string($parameter->var->name)
            || $parameter->byRef
            || $parameter->variadic
            || $parameter->default !== null
        ) {
            return null;
        }
        $aliases = [];
        foreach ($callback->stmts as $statement) {
            if ($statement instanceof Node\Stmt\Nop) {
                continue;
            }
            if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) {
                return null;
            }
            $call = $statement->expr;
            if (
                ! $call->var instanceof Node\Expr\Variable
                || $call->var->name !== $parameter->var->name
                || ! $call->name instanceof Node\Identifier
                || strtolower($call->name->toString()) !== 'alias'
                || count($call->args) !== 1
            ) {
                return null;
            }
            $argument = PhpSource::argument($call->args, 0, 'aliases');
            $aliases = $argument instanceof Node\Expr\Array_ ? self::literalMap($argument) : null;
            if ($aliases === null) {
                return null;
            }
        }

        return $aliases;
    }

    /** @return array<string, string>|null */
    private static function literalMap(Node\Expr\Array_ $array): ?array
    {
        $aliases = [];
        foreach ($array->items as $item) {
            if ($item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_) {
                return null;
            }
            $name = $item->key->value;
            if (
                $item->value instanceof Node\Expr\ClassConstFetch
                && $item->value->class instanceof Node\Name
                && in_array(strtolower($item->value->class->toString()), ['self', 'static', 'parent'], true)
            ) {
                return null;
            }
            $target = PhpSource::value($item->value);
            if (
                $name === ''
                || is_numeric($name)
                || preg_match('/[\\x00-\\x20\\x7f:]/', $name)
                || ! is_string($target)
                || ! preg_match(
                    '~^\\\\?[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*(?:\\\\[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*)*$~D',
                    $target,
                )
            ) {
                return null;
            }
            $aliases[$name] = $target;
        }

        return $aliases;
    }
}
