<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredReferencePossibleWrites;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Exact stale null/never diagnostics for closed possible reference writes. */
final class StoredReferencePossibleWriteFilter implements IssueFilterHook
{
    public function __construct(public readonly StoredReferencePossibleWrites $proofs) {}
    public function getCodes(): array { return ['impossible-assignment', 'impossible-type-comparison', 'mixed-assignment']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || !in_array($issue->level,[Level::Error,Level::Warning],true) || $issue->edits !== [] || $issue->link !== null
            || count($issue->notes) !== 1 || !in_array($issue->code, $this->getCodes(), true)) { return IssueFilterDecision::Keep; }
        foreach ($this->proofs->proofs($context->file, $context->contents) as $proof) {
            if (!self::envelope($context, $proof)) { continue; }
            $domain = $this->proofs->domain($context->codebase, $context->types, $proof);
            // Addition remains int|float. No inferred type, other issue, or output contract is modified.
            $completion = Type::union(Type::int(), Type::float());
            if ($domain !== null && $context->types->isContainedBy(Type::int(), $domain)
                && $context->types->isContainedBy(Type::int(), $completion) && !$context->types->isContainedBy($completion, Type::never())) {
                return IssueFilterDecision::Remove;
            }
        }
        return IssueFilterDecision::Keep;
    }
    private static function envelope(IssueFilterContext $context, array $proof): bool {
        $issue = $context->issue; $primary = $issue->annotations[0] ?? null;
        if ($primary === null || $primary->kind !== AnnotationKind::Primary || $primary->file !== null && $primary->file !== ''
            || $primary->span->start < 0 || $primary->span->end <= $primary->span->start || $primary->span->end > strlen($context->contents)) { return false; }
        if($issue->code==='mixed-assignment') {
            $assignment=$proof['writer']->stmts[0]??null;$assignment=$assignment instanceof \PhpParser\Node\Stmt\Expression?$assignment->expr:null;
            return $issue->level===Level::Warning && count($issue->annotations)===1 && $assignment instanceof \PhpParser\Node\Expr\Assign
                && $issue->message==='Assigning `mixed` type to a variable may lead to unexpected behavior.'
                && $issue->notes===['Using `mixed` can lead to runtime errors if the variable is used in a way that assumes a specific type.']
                && $issue->help==='Consider using a more specific type to avoid potential issues.'
                && $primary->message==='Assigning `mixed` type here.'
                && RefreshedModelProperties::key($assignment->var)===$primary->span->start.':'.$primary->span->end;
        }
        if($issue->level!==Level::Error){return false;}
        if ($issue->code === 'impossible-type-comparison') {
            $local = '$'.$proof['local'];
            return count($issue->annotations) === 1 && $issue->message === 'Impossible type assertion: `'.$local.'` of type `null` can never be `int`.'
                && $issue->notes === ['The assertion expects `'.$local.'` to be `int`, but no value of type `null` can satisfy this.']
                && $issue->help === 'Check that the correct variable is being passed, or update the assertion type.'
                && $primary->message === 'Argument `'.$local.'` has type `null`'
                && RefreshedModelProperties::key($proof['predicate']) === $primary->span->start.':'.$primary->span->end;
        }
        $secondary = $issue->annotations[1] ?? null;
        return count($issue->annotations) === 2 && $secondary !== null && $secondary->kind === AnnotationKind::Secondary
            && ($secondary->file === null || $secondary->file === '')
            && $issue->message === 'Invalid assignment: the right-hand side has type `never` and cannot produce a value.'
            && $issue->notes === ['An expression with type `never` is guaranteed to exit, throw, or loop indefinitely.']
            && $issue->help === 'This assignment is unreachable because the right-hand side never completes. Remove the assignment or refactor the preceding code.'
            && $primary->message === 'Cannot assign a `never` type value here' && $secondary->message === 'This expression has type `never`.'
            && RefreshedModelProperties::key($proof['assignment']->var) === $primary->span->start.':'.$primary->span->end
            && RefreshedModelProperties::key($proof['assignment']->expr) === $secondary->span->start.':'.$secondary->span->end;
    }
}
