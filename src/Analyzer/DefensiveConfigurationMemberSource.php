<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** A dependent configuration field is inspected only to append a validation diagnostic. */
final class DefensiveConfigurationMemberSource
{
    /** @return array<string, array<string, mixed>> */
    public static function compile(array $nodes): array
    {
        $guards = DefensiveBoundaryGuardSource::compile($nodes);
        $finder = new NodeFinder();
        $result = [];
        foreach ($guards as $proof) {
            if (!isset($proof['classification']) || $proof['selectedSite']['kind'] !== 'condition') {
                continue;
            }
            $outer = $finder->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\If_
                && DefensiveBoundaryGuardSource::span($node) === $proof['guard']);
            if (!$outer instanceof Node\Stmt\If_ || count($outer->stmts) !== 2) {
                continue;
            }
            $assignment = $outer->stmts[0] instanceof Node\Stmt\Expression ? $outer->stmts[0]->expr : null;
            $inner = $outer->stmts[1];
            if (!$assignment instanceof Node\Expr\Assign || !$assignment->var instanceof Node\Expr\Variable
                || !is_string($assignment->var->name) || !$assignment->expr instanceof Node\Expr\BinaryOp\Coalesce
                || !$assignment->expr->left instanceof Node\Expr\ArrayDimFetch
                || !$assignment->expr->left->var instanceof Node\Expr\Variable
                || !$assignment->expr->left->dim instanceof Node\Scalar\String_
                || !$inner instanceof Node\Stmt\If_ || !$inner->cond instanceof Node\Expr\BinaryOp\BooleanOr
                || !$inner->cond->left instanceof Node\Expr\BooleanNot
                || !$inner->cond->left->expr instanceof Node\Expr\FuncCall
                || !$inner->cond->right instanceof Node\Expr\BooleanNot
                || !$inner->cond->right->expr instanceof Node\Expr\Isset_) {
                continue;
            }
            $predicate = $inner->cond->left->expr;
            if (!$predicate->name instanceof Node\Name || strtolower($predicate->name->toString()) !== 'is_array'
                || count($predicate->args) !== 1 || !$predicate->args[0] instanceof Node\Arg
                || !$predicate->args[0]->value instanceof Node\Expr\Variable
                || $predicate->args[0]->value->name !== $assignment->var->name) {
                continue;
            }
            $keys = [];
            foreach ($inner->cond->right->expr->vars as $member) {
                if (!$member instanceof Node\Expr\ArrayDimFetch || !$member->var instanceof Node\Expr\Variable
                    || $member->var->name !== $assignment->var->name || !$member->dim instanceof Node\Scalar\String_
                    || $member->dim->value === '' || strlen($member->dim->value) > 128
                    || isset($keys[$member->dim->value])) {
                    continue 2;
                }
                $keys[$member->dim->value] = true;
            }
            if ($keys === [] || count($keys) > 16) {
                continue;
            }
            $predicateSpan = DefensiveBoundaryGuardSource::span($predicate);
            $base = $guards[DefensiveBoundaryGuardSource::key($predicateSpan)] ?? null;
            if ($base === null || $base['selectedSite']['kind'] !== 'predicate'
                || $base['selectedSite']['name'] !== 'is_array'
                || count($base['predicates']) !== 1 || $base['predicates'][0]['origin']['family'] !== 'configuration'
                || $base['rejection']['kind'] !== 'append-diagnostic') {
                continue;
            }
            $scope = $finder->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\FunctionLike
                && DefensiveBoundaryGuardSource::span($node) === $base['scope']['span']);
            if (!$scope instanceof Node\FunctionLike
                || !self::unaliased($scope, [$assignment->var->name, $assignment->expr->left->var->name])) {
                continue;
            }
            $entry = ['predicate' => $predicateSpan, 'value' => $assignment->var->name,
                'record' => $assignment->expr->left->var->name, 'field' => $assignment->expr->left->dim->value,
                'classification' => $proof['classification'], 'guard' => DefensiveBoundaryGuardSource::span($inner)];
            foreach (['key' => $assignment->expr->left->dim, 'coalesce' => $assignment->expr,
                'predicate' => $predicate] as $kind => $node) {
                $result[DefensiveBoundaryGuardSource::key(DefensiveBoundaryGuardSource::span($node))] = $entry + ['kind' => $kind];
            }
        }

        return $result;
    }

    private static function unaliased(Node\FunctionLike $scope, array $names): bool
    {
        $finder = new NodeFinder();
        foreach ($finder->find($scope, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Expr\ClosureUse && $node->byRef
            || $node instanceof Node\Arg && $node->byRef
            || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef) as $reference) {
            foreach ($finder->findInstanceOf($reference, Node\Expr\Variable::class) as $variable) {
                if (is_string($variable->name) && in_array($variable->name, $names, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
