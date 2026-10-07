<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Syntax-only certificate for a native equality advisory on an ordinary foreach header. */
final class ForeachHeaderObjectDocblockProof
{
    private const RESERVED = ['this', 'GLOBALS', '_ENV', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST'];

    public static function value(Node\Stmt\Foreach_ $loop, Node\FunctionLike $scope, string $contents): ?Node\Expr\Variable
    {
        if ($loop->byRef || $loop->keyVar !== null || ! self::local($loop->valueVar) || ! self::local($loop->expr)
            || $loop->valueVar->name === $loop->expr->name) { return null; }
        $doc = $loop->getDocComment();
        if ($doc === null || $doc->getEndFilePos() >= $loop->getStartFilePos()
            || preg_match('/\A[ \t\r\n]*\z/D', substr($contents, $doc->getEndFilePos() + 1,
                $loop->getStartFilePos() - $doc->getEndFilePos() - 1)) !== 1) { return null; }
        $value = $loop->valueVar->name; $iterable = $loop->expr->name;
        foreach ($scope->getParams() as $parameter) {
            if ($parameter->byRef || $parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $value) { return null; }
        }
        foreach ((new NodeFinder)->find([$scope], static fn (Node $node): bool => $node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\Variable
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Unset_
            || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Arg
            || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label) as $node) {
            if ($node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Stmt\Goto_
                || $node instanceof Node\Stmt\Label || $node instanceof Node\Expr\Variable && ! is_string($node->name)
                || $node instanceof Node\Arg && $node->byRef) { return null; }
            if ($node instanceof Node\Expr\Assign) {
                // Ordinary initial iterable binding is allowed; storage aliasing and selected value writes are not.
                if (self::root($node->expr, [$value, $iterable]) || self::root($node->var, [$value])
                    || $node->getStartFilePos() >= $doc->getStartFilePos() && self::root($node->var, [$iterable])) { return null; }
            } elseif ($node instanceof Node\Expr\AssignOp && self::root($node->var, [$value, $iterable])) { return null; }
            elseif (($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec)
                && self::root($node->var, [$value, $iterable])) { return null; }
            elseif ($node instanceof Node\Expr\Closure) {
                foreach ($node->uses as $use) { if (self::root($use->var, [$value, $iterable])) { return null; } }
            } elseif ($node instanceof Node\Expr\ArrowFunction) {
                if ((new NodeFinder)->findFirst([$node->expr], static fn (Node $child): bool => $child instanceof Node\Expr\Variable
                    && in_array($child->name, [$value, $iterable], true)) !== null) { return null; }
            } elseif ($node instanceof Node\Stmt\Unset_) {
                foreach ($node->vars as $variable) { if (self::root($variable, [$value, $iterable])) { return null; } }
            } elseif ($node instanceof Node\Stmt\Global_) {
                foreach ($node->vars as $variable) { if (self::root($variable, [$value, $iterable])) { return null; } }
            } elseif ($node instanceof Node\Stmt\Static_) {
                foreach ($node->vars as $variable) { if (self::root($variable->var, [$value, $iterable])) { return null; } }
            } elseif ($node instanceof Node\Stmt\Foreach_ && $node !== $loop
                && ($node->byRef || self::root($node->valueVar, [$value]) || $node->keyVar !== null && self::root($node->keyVar, [$value]))) { return null; }
        }
        return $loop->valueVar;
    }

    private static function local(Node $node): bool
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name) && ! in_array($node->name, self::RESERVED, true);
    }

    /** @param list<string> $names */
    private static function root(Node $node, array $names): bool
    {
        while ($node instanceof Node\Expr\ArrayDimFetch || $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) { $node = $node->var; }
        return $node instanceof Node\Expr\Variable && in_array($node->name, $names, true);
    }
}
