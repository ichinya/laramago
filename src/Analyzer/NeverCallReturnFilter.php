<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook};
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\{Node, NodeFinder, NodeTraverser, ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** A call with an enforced PHP never return cannot reach the enclosing return statement. */
final class NeverCallReturnFilter implements IssueFilterHook
{
    public function getCodes(): array { return ['never-return']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($issue->message !== "Cannot return value with type 'never' from this function." || count($issue->annotations) !== 2
            || strlen($context->contents) > 2_000_000) { return IssueFilterDecision::Keep; }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $secondary->kind !== AnnotationKind::Secondary
            || $primary->message !== "This expression has type 'never'." || $secondary->message !== 'This return statement is effectively unreachable.'
            || $primary->file !== null && $primary->file !== '' || $secondary->file !== null && $secondary->file !== '') { return IssueFilterDecision::Keep; }
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? []);
        } catch (\PhpParser\Error) { return IssueFilterDecision::Keep; }
        $finder = new NodeFinder;
        $return = $finder->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_
            && $node->getStartFilePos() === $secondary->span->start && $node->getEndFilePos() + 1 === $secondary->span->end);
        $call = $return?->expr;
        if (! $call instanceof Node\Expr\CallLike || $call->isFirstClassCallable() || $call->getStartFilePos() !== $primary->span->start
            || $call->getEndFilePos() + 1 !== $primary->span->end) { return IssueFilterDecision::Keep; }
        $scopes = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\FunctionLike
            && $node->getStartFilePos() < $return->getStartFilePos() && $node->getEndFilePos() > $return->getEndFilePos());
        usort($scopes, static fn (Node $a, Node $b): int => ($a->getEndFilePos() - $a->getStartFilePos()) <=> ($b->getEndFilePos() - $b->getStartFilePos()));
        $scope = $scopes[0] ?? null;
        if ($scope === null || $scope->getReturnType() instanceof Node\Identifier
            && in_array(strtolower($scope->getReturnType()->name), ['never', 'void'], true)) { return IssueFilterDecision::Keep; }
        $native = null;
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            $namespaced = $call->name->getAttribute('namespacedName');
            $native = $namespaced instanceof Node\Name ? $context->codebase->getFunction($namespaced->toString()) : null;
            $native ??= $context->codebase->getFunction($call->name->toString());
        } elseif ($call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier
            && $call->var instanceof Node\Expr\Variable && $call->var->name === 'this' && $scope instanceof Node\Stmt\ClassMethod) {
            $owner = $finder->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_
                && in_array($scope, $node->getMethods(), true));
            if ($owner?->namespacedName !== null) { $native = $context->codebase->getDeclaringMethod($owner->namespacedName->toString(), $call->name->name); }
        }
        return (string) $native?->declaredReturnType?->type === 'never' ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
