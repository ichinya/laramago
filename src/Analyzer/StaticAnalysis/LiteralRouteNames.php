<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Strict syntax input to an explicitly asserted active named-route catalog. */
final class LiteralRouteNames
{
    /** @return list<string>|null */
    public static function fromFiles(string $root, mixed $files): ?array
    {
        if (! is_array($files) || ! array_is_list($files)) {
            return null;
        }
        $directory = realpath($root);
        if ($directory === false) {
            return null;
        }
        $directory = str_replace('\\', '/', $directory).'/';
        $source = new PhpSource($root);
        $names = [];
        /** @var mixed $file */
        foreach ($files as $file) {
            if (
                ! is_string($file)
                || $file === ''
                || str_contains($file, "\0")
                || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
                || ! str_ends_with($file, '.php')
            ) {
                return null;
            }
            $path = realpath($directory.$file);
            if ($path === false || ! str_starts_with(str_replace('\\', '/', $path), $directory) || ! is_file($path)) {
                return null;
            }
            $nodes = $source->read($path);
            $found = $nodes === null ? null : self::statements($nodes, '');
            if ($found === null) {
                return null;
            }
            array_push($names, ...$found);
        }

        return $names;
    }

    /** @param array<array-key, Node> $nodes
     * @return list<string>|null
     */
    private static function statements(array $nodes, string $prefix): ?array
    {
        $names = [];
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
            $found = match (true) {
                $node instanceof Node\Stmt\Namespace_ => self::statements($node->stmts, $prefix),
                $node instanceof Node\Stmt\Expression => self::expression($node->expr, $prefix),
                default => null,
            };
            if ($found === null) {
                return null;
            }
            array_push($names, ...$found);
        }

        return $names;
    }

    /** @return list<string>|null */
    private static function expression(Node\Expr $expression, string $prefix): ?array
    {
        [$base, $calls] = PhpSource::chain($expression);
        if (
            ! $base instanceof Node\Expr\StaticCall
            || ! $base->class instanceof Node\Name
            || strtolower($base->class->toString()) !== 'illuminate\\support\\facades\\route'
            || ! $base->name instanceof Node\Identifier
        ) {
            return null;
        }
        $method = strtolower($base->name->toString());
        if ($base->name->toString() === 'name' && count($calls) === 1 && self::positional($base->args, 1)) {
            $name = $base->args[0]->value;
            $group = $calls[0];
            if (
                ! $name instanceof Node\Scalar\String_
                || ! $group->name instanceof Node\Identifier
                || strtolower($group->name->toString()) !== 'group'
                || ! self::positional($group->args, 1)
            ) {
                return null;
            }

            return self::group($group->args[0]->value, $prefix.$name->value);
        }
        if ($method === 'group' && $calls === [] && self::positional($base->args, 2)) {
            $attributes = $base->args[0]->value;
            if (! $attributes instanceof Node\Expr\Array_ || count($attributes->items) !== 1) {
                return null;
            }
            $attribute = $attributes->items[0];
            if (
                $attribute->unpack
                || $attribute->byRef
                || ! $attribute->key instanceof Node\Scalar\String_
                || $attribute->key->value !== 'as'
                || ! $attribute->value instanceof Node\Scalar\String_
            ) {
                return null;
            }

            return self::group($base->args[1]->value, $prefix.$attribute->value->value);
        }
        if (
            ! in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'], true)
            || ! self::positional($base->args, 2)
            || ! $base->args[0]->value instanceof Node\Scalar\String_
            || ! self::action($base->args[1]->value)
            || $calls === []
        ) {
            return null;
        }
        $name = $prefix;
        foreach ($calls as $call) {
            if (
                ! $call->name instanceof Node\Identifier
                || strtolower($call->name->toString()) !== 'name'
                || ! self::positional($call->args, 1)
                || ! $call->args[0]->value instanceof Node\Scalar\String_
            ) {
                return null;
            }
            $name .= $call->args[0]->value->value;
        }

        return $name === '' ? null : [$name];
    }

    /** @return list<string>|null */
    private static function group(Node\Expr $callback, string $prefix): ?array
    {
        if (! $callback instanceof Node\Expr\Closure || $callback->params !== [] || $callback->uses !== []) {
            return null;
        }

        return self::statements($callback->stmts, $prefix);
    }

    private static function action(Node\Expr $action): bool
    {
        if ($action instanceof Node\Expr\Closure || $action instanceof Node\Expr\ArrowFunction) {
            // A handler's body is not a registration body and must never be traversed.
            return true;
        }
        if ($action instanceof Node\Scalar\String_) {
            return $action->value !== '';
        }
        if ($action instanceof Node\Expr\ClassConstFetch) {
            return (
                $action->class instanceof Node\Name\FullyQualified
                && $action->name instanceof Node\Identifier
                && strtolower($action->name->toString()) === 'class'
            );
        }
        if (! $action instanceof Node\Expr\Array_ || count($action->items) !== 2) {
            return false;
        }
        [$controller, $method] = $action->items;

        return (
            ! $controller->unpack
            && ! $controller->byRef
            && $controller->key === null
            && ! $method->unpack
            && ! $method->byRef
            && $method->key === null
            && (
                $controller->value instanceof Node\Scalar\String_
                || $controller->value instanceof Node\Expr\ClassConstFetch
            )
            && self::action($controller->value)
            && $method->value instanceof Node\Scalar\String_
            && $method->value->value !== ''
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @phpstan-assert-if-true list<Node\Arg> $args
     */
    private static function positional(array $args, int $count): bool
    {
        if (count($args) !== $count || ! array_is_list($args)) {
            return false;
        }
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name !== null) {
                return false;
            }
        }

        return true;
    }
}
