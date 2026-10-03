<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;

/** Repair only the precise nested access whose conditional native array fact was lost. */
final class ConditionalArrayGuardIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly ConditionalArrayGuards $provenance = new ConditionalArrayGuards) {}

    public function getCodes(): array { return ['mixed-array-access']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        $annotation = $issue->annotations[0] ?? null;
        if ($issue->code !== 'mixed-array-access' || $issue->message !== 'Unsafe array access on type `mixed`.'
            || count($issue->annotations) !== 1 || $annotation === null || $annotation->kind !== AnnotationKind::Primary
            || $annotation->message !== 'Cannot safely access index because base type is `mixed`.'
            || $annotation->file !== null && $annotation->file !== '' || $annotation->span->start < 0
            || $annotation->span->end > strlen($context->contents)) { return IssueFilterDecision::Keep; }
        $key = $annotation->span->start.':'.$annotation->span->end;
        foreach ($this->provenance->proofs($context->file, $context->contents) as $proof) {
            if (ConditionalArrayGuards::key($proof['access']) === $key
                && ConditionalArrayGuards::current($proof) && ConditionalArrayGuards::valid($context->codebase, $proof)) {
                return IssueFilterDecision::Remove;
            }
        }
        return IssueFilterDecision::Keep;
    }
}
