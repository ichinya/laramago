<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Expand a bounded, declarative table loop without evaluating migration code. */
final class MigrationTableLoop
{
    /** @return list<Node\Expr\StaticCall>|null */
    public static function expand(Node\Stmt\Foreach_ $loop, Node\Stmt\Class_ $class): ?array
    {
        $name = $loop->valueVar instanceof Node\Expr\Variable ? $loop->valueVar->name : null;
        $values = self::tables($loop->expr, $class);
        if ($loop->byRef || $loop->keyVar !== null || ! is_string($name)
            || in_array($name, ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true)
            || $values === null || count($values) > 64 || count($loop->stmts) > 64
            || count($values) * count($loop->stmts) > 256) {
            return null;
        }

        $templates = [];
        foreach ($loop->stmts as $statement) {
            if ($statement instanceof Node\Stmt\Nop) {
                continue;
            }
            $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (! $call instanceof Node\Expr\StaticCall || ! $call->class instanceof Node\Name
                || ! in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
                || ! $call->name instanceof Node\Identifier
                || ! in_array(strtolower($call->name->toString()), ['table', 'create'], true)
                || count($call->args) !== 2) {
                return null;
            }
            $table = PhpSource::argument($call->args, 0, 'table');
            $callback = PhpSource::argument($call->args, 1, 'callback');
            if (! $table instanceof Node\Expr\Variable || $table->name !== $name
                || ! $callback instanceof Node\Expr\Closure
                || (new NodeFinder)->findFirst([$callback], static fn (Node $node): bool =>
                    $node instanceof Node\Expr\Variable && $node->name === $name) !== null) {
                return null;
            }
            foreach ($call->args as $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                    return null;
                }
            }
            $templates[] = [$call, $table];
        }
        $calls = [];
        foreach ($values as $value) {
            foreach ($templates as [$template, $table]) {
                $call = clone $template;
                $call->args = array_map(static function (Node\Arg $argument) use ($table, $value): Node\Arg {
                    $copy = clone $argument;
                    if ($argument->value === $table) {
                        $copy->value = new Node\Scalar\String_($value, $table->getAttributes());
                    }

                    return $copy;
                }, $call->args);
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /** @return list<string>|null */
    public static function tablesFor(Node\Expr $expression, Node\Stmt\Class_ $class): ?array
    {
        return self::tables($expression, $class);
    }

    /** @param array<string, true> $visited @return list<string>|null */
    private static function tables(Node\Expr $expression, Node\Stmt\Class_ $class, array $visited = []): ?array
    {
        if ($expression instanceof Node\Expr\ClassConstFetch && $expression->class instanceof Node\Name
            && strtolower($expression->class->toString()) === 'self' && $expression->name instanceof Node\Identifier) {
            $name = $expression->name->toString();
            if (isset($visited[$name]) || count($visited) >= 16) {
                return null;
            }
            $visited[$name] = true;
            foreach ($class->getConstants() as $declaration) {
                foreach ($declaration->consts as $constant) {
                    if ($constant->name->toString() === $name) {
                        return self::tables($constant->value, $class, $visited);
                    }
                }
            }

            return null;
        }
        if (! $expression instanceof Node\Expr\Array_ || count($expression->items) > 64) {
            return null;
        }
        $tables = [];
        foreach ($expression->items as $item) {
            if ($item === null || $item->key !== null || $item->unpack || $item->byRef
                || ! $item->value instanceof Node\Scalar\String_ || $item->value->value === '') {
                return null;
            }
            $tables[] = $item->value->value;
        }

        return $tables;
    }
}
