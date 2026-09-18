<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Recognizes literal, exactly typed factories without invoking application PHP. */
final class ContainerFactory
{
    public static function concrete(?Node $node): ?string
    {
        if (
            ! ($node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction)
            || $node->byRef
            || ! self::acceptsContainerArguments($node->params)
            || ! $node->returnType instanceof Node\Name\FullyQualified
            || $node->attrGroups !== []
        ) {
            return null;
        }
        if ($node instanceof Node\Expr\Closure) {
            if ($node->uses !== [] || count($node->stmts) !== 1 || ! $node->stmts[0] instanceof Node\Stmt\Return_) {
                return null;
            }
            $expression = $node->stmts[0]->expr;
        } else {
            $expression = $node->expr;
        }
        if (
            ! $expression instanceof Node\Expr\New_
            || ! $expression->class instanceof Node\Name\FullyQualified
            || $expression->args !== []
            || strcasecmp($expression->class->toString(), $node->returnType->toString()) !== 0
        ) {
            return null;
        }

        return $expression->class->toString();
    }

    /** @param array<array-key, Node\Param> $parameters */
    private static function acceptsContainerArguments(array $parameters): bool
    {
        if (count($parameters) > 2) {
            return false;
        }
        foreach ($parameters as $parameter) {
            if (
                $parameter->type !== null
                || $parameter->default !== null
                || $parameter->byRef
                || $parameter->variadic
                || $parameter->attrGroups !== []
            ) {
                return false;
            }
        }

        return true;
    }
}
