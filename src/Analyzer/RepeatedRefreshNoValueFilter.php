<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook};
use Mago\Sdk\Reporting\{AnnotationKind, Level};
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Restore only a second native refresh's independently admissible model attribute read. */
final class RepeatedRefreshNoValueFilter implements IssueFilterHook
{
    /** Debug receipts are published after evaluation; they never authorize a later decision. */
    public array $stages = [];
    public array $dependencies = [];
    public array $certificate = [];

    public function __construct(public readonly RefreshedModelProperties $provenance, private readonly string $root = '.') {}

    public function getCodes(): array { return ['no-value']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        // SDK queries may enter this hook again. Keep all in-progress proof state local.
        $evaluation = new self(clone $this->provenance, $this->root);
        $decision = $evaluation->evaluateIssue($context);
        $this->stages = $evaluation->stages;
        $this->dependencies = $evaluation->dependencies;
        $this->certificate = $evaluation->certificate;

        return $decision;
    }

    private function stage(string $name, bool $passed): bool
    {
        $this->stages[] = ['stage' => $name, 'passed' => $passed];

        return $passed;
    }

    private function evaluateIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if (! $this->stage('complete selected no-value argument Error', $issue->code === 'no-value' && $issue->level === Level::Error
            && $issue->message === 'Argument #1 passed to method `Testo\\Assert::same` has type `never`, meaning it cannot produce a value.'
            && $issue->notes === [
                'The `never` type means no value can reach this point at runtime - this code path is unreachable.',
                'This often occurs in unreachable code, due to impossible conditional logic, or if an expression always exits (e.g., `throw`, `exit()`).',
            ]
            && $issue->help === 'Review preceding logic to ensure this argument can receive a value, or remove if unreachable.'
            && $issue->edits === [] && $issue->link === null && count($issue->annotations) === 1)) { return IssueFilterDecision::Keep; }
        $primary = $issue->annotations[0];
        if (! $this->stage('one entire local no-value expression', $primary->kind === AnnotationKind::Primary
            && ($primary->file === null || $primary->file === '') && $primary->message === 'This argument expression results in type `never`')) { return IssueFilterDecision::Keep; }
        $proofs = $this->provenance->proofs($context->file, $context->contents);
        foreach ($proofs as $proof) {
            if (RefreshedModelProperties::key($proof['argument']->value) !== $primary->span->start.':'.$primary->span->end
                || strtolower($proof['assertion']->name->name) !== 'same') { continue; }
            if (! $this->stage('current source-selected refresh and changed literal assertion', RefreshedModelProperties::current($proof))) { return IssueFilterDecision::Keep; }
            $earlier = self::earlierRefresh($proof);
            if (! $this->stage('two unconditional refreshes on one closed local receiver', $earlier !== null)) { return IssueFilterDecision::Keep; }
            if (! $this->stage('no source termination before selected assertion', self::continues($proof))) { return IssueFilterDecision::Keep; }
            $fields = new RepeatedRefreshOwnerFields($this->root);
            $contracts = $fields->resolve($context, $proof);
            $this->dependencies = $fields->dependencies;
            if (! $this->stage('physical typed helper owner fields and unrelated native domains', $contracts !== null)) { return IssueFilterDecision::Keep; }
            if (! $this->stage('unchanged native refresh assertion read casts schema and declared domain', $this->provenance->valid($context->codebase, $context->types, $proof))) { return IssueFilterDecision::Keep; }
            if (! $this->stage('current physical source after native queries', RefreshedModelProperties::current($proof) && $fields->current())) { return IssueFilterDecision::Keep; }
            $this->certificate = [
                'sourceSha256' => $proof['hash'], 'caller' => $proof['owner'].'::'.$proof['scope']->name->name,
                'receiver' => '$'.$proof['receiver'], 'property' => $proof['property'],
                'originSpan' => RefreshedModelProperties::key($proof['origin']),
                'earlierRefreshSpan' => RefreshedModelProperties::key($earlier),
                'previousAssertionSpan' => RefreshedModelProperties::key($proof['observation']['assertion']),
                'selectedRefreshSpan' => RefreshedModelProperties::key($proof['refresh']),
                'selectedAssertionSpan' => RefreshedModelProperties::key($proof['assertion']),
                'selectedArgumentSpan' => RefreshedModelProperties::key($proof['argument']->value),
                'previousLiteral' => $proof['observation']['expected'], 'nextLiteral' => $proof['expected'],
                'helperOwnerFields' => $contracts, 'policy' => 'Possible changed native model attribute after a repeated refresh; no database execution claim.',
            ];

            return IssueFilterDecision::Remove;
        }

        return IssueFilterDecision::Keep;
    }

    private static function earlierRefresh(array $proof): ?Node\Expr\MethodCall
    {
        foreach ($proof['scope']->stmts ?? [] as $statement) {
            $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if ($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\Variable && $call->var->name === $proof['receiver']
                && $call->name instanceof Node\Identifier && strtolower($call->name->name) === 'refresh' && $call->args === []
                && $call->getStartFilePos() > $proof['origin']->getEndFilePos()
                && $call->getEndFilePos() < $proof['observation']['assertion']->getStartFilePos()
                && $call->getEndFilePos() < $proof['refresh']->getStartFilePos()) { return $call; }
        }

        return null;
    }

    private static function continues(array $proof): bool
    {
        $prefix = array_filter($proof['scope']->stmts ?? [], static fn (Node $statement): bool => $statement->getStartFilePos() < $proof['assertion']->getStartFilePos());

        return (new NodeFinder)->findFirst($prefix, static function (Node $node) use ($proof): bool {
            if ($node instanceof Node\Expr\Exit_ || $node instanceof Node\Expr\Throw_ || $node instanceof Node\Stmt\Return_
                || $node instanceof Node\Stmt\Break_ || $node instanceof Node\Stmt\Continue_) { return true; }
            if ($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier) {
                $callee = $proof['callerClass']->getMethod($node->name->name);
                return $callee?->returnType instanceof Node\Identifier && strtolower($callee->returnType->name) === 'never';
            }

            return $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier
                && strcasecmp($node->class->toString(), 'Testo\\Assert') === 0 && in_array(strtolower($node->name->name), ['fail', 'unreachable'], true);
        }) === null;
    }
}
