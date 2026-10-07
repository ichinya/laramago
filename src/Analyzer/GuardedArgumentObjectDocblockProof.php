<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use PhpParser\Node;

/** Classify an immediate documented local argument after an ordinary instanceof guard. */
final class GuardedArgumentObjectDocblockProof
{
    /** @return array<int, Node\Expr\Instanceof_> */
    public static function guards(Node\FunctionLike $scope): array
    {
        $guards = [];
        foreach ($scope->getStmts() ?? [] as $statement) {
            if (! $statement instanceof Node\Stmt\If_) { continue; }
            foreach ([$statement, ...$statement->elseifs] as $branch) {
                $condition = $branch->cond;
                $first = $branch->stmts[0] ?? null;
                if ($condition instanceof Node\Expr\Instanceof_ && $condition->expr instanceof Node\Expr\Variable
                    && is_string($condition->expr->name) && $condition->class instanceof Node\Name
                    && $first instanceof Node\Stmt\Expression && self::value($first) !== null) {
                    $guards[spl_object_id($first)] = $condition;
                }
            }
        }
        return $guards;
    }

    public static function value(Node\Stmt\Expression $statement): ?Node\Expr\Variable
    {
        $call = $statement->expr;
        if (! $call instanceof Node\Expr\MethodCall || ! $call->var instanceof Node\Expr\Variable
            || $call->var->name !== 'this' || ! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()
            || count($call->args) !== 1) { return null; }
        $argument = $call->args[0];
        if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null
            || ! $argument->value instanceof Node\Expr\Variable || ! is_string($argument->value->name)) { return null; }
        return $argument->value;
    }

    public static function matches(Node\Expr\Instanceof_ $guard, Node\Expr\Variable $variable, string $type): bool
    {
        return $guard->expr instanceof Node\Expr\Variable && $guard->expr->name === $variable->name
            && $guard->class instanceof Node\Name && ! in_array(strtolower($guard->class->toString()), ['self', 'parent', 'static'], true)
            && strcasecmp($guard->class->toString(), $type) === 0;
    }
}
