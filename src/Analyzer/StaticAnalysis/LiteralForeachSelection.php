<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Prove exhaustive selection without evaluating calls or assuming their result types. */
final class LiteralForeachSelection
{
    private const SPECIAL_VARIABLES = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header'];
    private const INDIRECT_LOCAL_CALLS = ['extract', 'parse_str', 'mb_parse_str', 'call_user_func', 'call_user_func_array'];

    /**
     * The caller must separately prove the producer's nonnullable result contract and
     * verify that every safeIntrinsics call resolves to the native builtin sprintf.
     * Tracked loop arguments are copied into fresh arrays or passed to that builtin;
     * they are never passed directly to an unknown by-reference parameter.
     *
     * @return array{sentinel: string, producer: Node\Expr, candidate: string, loop: Node\Stmt\Foreach_, value: string, key: ?string, safeIntrinsics: list<Node\Expr\FuncCall>}|null
     */
    public static function prove(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope, Node\Stmt\Return_ $return): ?array
    {
        $statements = $scope->stmts ?? [];
        $sentinel = self::local($return->expr);
        if ($scope->byRef || $sentinel === null || ($statements[count($statements) - 1] ?? null) !== $return) {
            return null;
        }
        foreach ($statements as $index => $statement) {
            if (! $statement instanceof Node\Stmt\Foreach_ || $index === 0) {
                continue;
            }
            $initializer = $statements[$index - 1];
            $assignment = $initializer instanceof Node\Stmt\Expression ? $initializer->expr : null;
            if (! $assignment instanceof Node\Expr\Assign || self::local($assignment->var) !== $sentinel
                || ! $assignment->expr instanceof Node\Expr\ConstFetch
                || strtolower($assignment->expr->name->toString()) !== 'null') {
                continue;
            }
            $value = self::local($statement->valueVar);
            $key = $statement->keyVar === null ? null : self::local($statement->keyVar);
            $producerAssignment = $statement->stmts[0] ?? null;
            $selection = $statement->stmts[1] ?? null;
            if ($statement->byRef || $value === null || $statement->keyVar !== null && $key === null
                || count($statement->stmts) !== 2 || ! $producerAssignment instanceof Node\Stmt\Expression
                || ! $producerAssignment->expr instanceof Node\Expr\Assign
                || ! $selection instanceof Node\Stmt\If_ || $selection->else !== null || $selection->elseifs !== []
                || count($selection->stmts) !== 1 || ! $selection->cond instanceof Node\Expr\BinaryOp\Identical) {
                continue;
            }
            $candidate = self::local($producerAssignment->expr->var);
            if ($candidate === null) {
                continue;
            }
            $tracked = [$sentinel, $candidate, $value, ...($key === null ? [] : [$key])];
            if (count(array_unique($tracked)) !== count($tracked)) {
                continue;
            }
            $successful = $selection->stmts[0];
            if (! $successful instanceof Node\Stmt\Expression || ! $successful->expr instanceof Node\Expr\Assign
                || self::local($successful->expr->var) !== $sentinel
                || self::local($successful->expr->expr) !== $candidate) {
                continue;
            }
            $condition = $selection->cond;
            $selector = self::local($condition->left) === $value ? self::scalar($condition->right)
                : (self::local($condition->right) === $value ? self::scalar($condition->left) : null);
            if ($selector === null || ! self::containsLiteral($statement->expr, $selector)) {
                continue;
            }
            foreach ($scope->params as $parameter) {
                if ($parameter->byRef || in_array(self::local($parameter->var), $tracked, true)) {
                    return null;
                }
            }
            $budget = 4096;
            if (! self::safeSyntax($scope, $budget)) {
                return null;
            }
            foreach ($statements as $otherIndex => $other) {
                if ($otherIndex === $index - 1 || $otherIndex === $index || $other === $return) {
                    continue;
                }
                // A fresh binding cannot have old values, escaped aliases or captures.
                // Later calls are admissible only if they cannot obtain any tracked local.
                if (self::mentions($other, $tracked)) {
                    return null;
                }
            }
            $producer = $producerAssignment->expr->expr;
            if (! $producer instanceof Node\Expr\FuncCall && ! $producer instanceof Node\Expr\StaticCall
                && ! $producer instanceof Node\Expr\MethodCall) {
                return null;
            }
            $intrinsics = [];
            if (! self::safeProducer($producer, $tracked, [$value, ...($key === null ? [] : [$key])], $intrinsics)) {
                return null;
            }

            return ['sentinel' => $sentinel, 'producer' => $producer, 'candidate' => $candidate,
                'loop' => $statement, 'value' => $value, 'key' => $key, 'safeIntrinsics' => $intrinsics];
        }

        return null;
    }

    private static function local(?Node $node): ?string
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name)
            && $node->name !== '' && ! in_array($node->name, self::SPECIAL_VARIABLES, true) ? $node->name : null;
    }

    /** @return array{bool|int|float|string}|null */
    private static function scalar(Node\Expr $expression): ?array
    {
        if ($expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\String_) {
            return [$expression->value];
        }
        if ($expression instanceof Node\Scalar\Float_) {
            return is_finite($expression->value) ? [$expression->value] : null;
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => [true],
                'false' => [false],
                default => null,
            };
        }
        if ($expression instanceof Node\Expr\UnaryMinus || $expression instanceof Node\Expr\UnaryPlus) {
            // Only one literal sign is supported; no runtime arithmetic or constants.
            $operand = $expression->expr;
            if (! $operand instanceof Node\Scalar\Int_ && ! $operand instanceof Node\Scalar\Float_) {
                return null;
            }
            $value = $expression instanceof Node\Expr\UnaryMinus ? -$operand->value : +$operand->value;

            return is_float($value) && ! is_finite($value) ? null : [$value];
        }

        return null;
    }

    /** @param array{bool|int|float|string} $selector */
    private static function containsLiteral(Node\Expr $expression, array $selector): bool
    {
        if (! $expression instanceof Node\Expr\Array_ || $expression->items === [] || count($expression->items) > 128) {
            return false;
        }
        $present = false;
        foreach ($expression->items as $item) {
            if ($item === null || $item->key !== null || $item->byRef || $item->unpack) {
                return false;
            }
            $literal = self::scalar($item->value);
            if ($literal === null) {
                return false;
            }
            $present = $present || $literal[0] === $selector[0];
        }

        return $present;
    }

    /** Reject syntax that can bypass selection or mutate undisclosed caller locals. */
    private static function safeSyntax(Node $node, int &$budget, int $depth = 0): bool
    {
        if (--$budget < 0 || $depth > 64
            || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Yield_
            || $node instanceof Node\Expr\YieldFrom || $node instanceof Node\Stmt\Goto_
            || $node instanceof Node\Stmt\Label || $node instanceof Node\Stmt\Break_
            || $node instanceof Node\Stmt\Continue_ || $node instanceof Node\Stmt\TryCatch
            || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Variable && ! is_string($node->name)
            || $node instanceof Node\Arg && $node->byRef
            || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Param && $node->byRef
            || $node instanceof Node\ClosureUse && $node->byRef
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef) {
            return false;
        }
        if ($node instanceof Node\Expr\FuncCall) {
            if (! $node->name instanceof Node\Name) {
                return false;
            }
            $parts = explode('\\', strtolower($node->name->toString()));
            if (in_array($parts[count($parts) - 1], self::INDIRECT_LOCAL_CALLS, true)) {
                return false;
            }
        }
        foreach ($node->getSubNodeNames() as $name) {
            $children = $node->$name;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node && ! self::safeSyntax($child, $budget, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $names */
    private static function mentions(Node $node, array $names): bool
    {
        if ($node instanceof Node\Expr\Variable && in_array($node->name, $names, true)) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $children = $node->$name;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node && self::mentions($child, $names)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $tracked
     * @param list<string> $loopLocals
     * @param list<Node\Expr\FuncCall> $intrinsics
     */
    private static function safeProducer(Node $node, array $tracked, array $loopLocals, array &$intrinsics, bool $copied = false): bool
    {
        if (! self::mentions($node, $tracked)) {
            return true;
        }
        if ($copied && $node instanceof Node\Expr && self::scalarExpression($node, $loopLocals)) {
            return true;
        }
        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null || $item->byRef || $item->unpack
                    || $item->key !== null && ! self::safeProducer($item->key, $tracked, $loopLocals, $intrinsics)
                    || ! self::safeProducer($item->value, $tracked, $loopLocals, $intrinsics, true)) {
                    return false;
                }
            }

            return true;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && strtolower($node->name->toString()) === 'sprintf' && ! $node->isFirstClassCallable()) {
            if ($node->args === [] || ! $node->args[0] instanceof Node\Arg
                || ! $node->args[0]->value instanceof Node\Scalar\String_) {
                return false;
            }
            foreach ($node->args as $argument) {
                if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null
                    || ! self::scalarExpression($argument->value, $loopLocals)) {
                    return false;
                }
            }
            $intrinsics[] = $node;

            return true;
        }
        if ($node instanceof Node\Expr\Variable || $node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PostDec || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction) {
            return false;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $children = $node->$name;
            foreach (is_array($children) ? $children : [$children] as $child) {
                // Copy permission never crosses an arbitrary call or expression.
                if ($child instanceof Node && ! self::safeProducer($child, $tracked, $loopLocals, $intrinsics)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $locals */
    private static function scalarExpression(Node\Expr $expression, array $locals): bool
    {
        if (self::scalar($expression) !== null || in_array(self::local($expression), $locals, true)) {
            return true;
        }
        if ($expression instanceof Node\Expr\BinaryOp\Plus || $expression instanceof Node\Expr\BinaryOp\Minus) {
            return self::scalarExpression($expression->left, $locals) && self::scalarExpression($expression->right, $locals);
        }

        return false;
    }
}
