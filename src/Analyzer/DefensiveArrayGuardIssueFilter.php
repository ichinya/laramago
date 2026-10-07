<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DefensiveArrayGuardProofs;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Diagnostic policy: the native array type and its redundancy are intentionally preserved. */
final class DefensiveArrayGuardIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly DefensiveArrayGuardProofs $proofs = new DefensiveArrayGuardProofs) {}

    public function getCodes(): array { return ['redundant-type-comparison']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->code !== 'redundant-type-comparison'
            || $issue->level !== Level::Warning || count($issue->annotations) !== 1 || count($issue->notes) !== 1
            || $issue->link !== null || $issue->edits !== []
            || $issue->help !== 'Consider removing this assertion or replacing it with `default` if used in a `match` arm.') {
            return IssueFilterDecision::Keep;
        }
        $primary = $issue->annotations[0];
        if ($primary->kind !== AnnotationKind::Primary || $primary->file !== null && $primary->file !== ''
            || $primary->span->start < 0 || $primary->span->end <= $primary->span->start
            || $primary->span->end > strlen($context->contents)) { return IssueFilterDecision::Keep; }
        foreach ($this->proofs->proofs($context->file, $context->contents) as $proof) {
            $variable = '$'.$proof['input'];
            if (DefensiveArrayGuardProofs::key($proof['predicate']) !== $primary->span->start.':'.$primary->span->end
                || $issue->message !== 'Redundant type assertion: `'.$variable.'` is already `array<array-key, mixed>`.'
                || $primary->message !== 'Argument `'.$variable.'` already has type `array<array-key, mixed>`'
                || $issue->notes[0] !== 'The assertion against `array<array-key, mixed>` always holds because `'.$variable.'` is `array<array-key, mixed>`.'
                || ! $this->proofs->valid($context->codebase, $context->types, $proof)) { continue; }
            return IssueFilterDecision::Remove;
        }
        return IssueFilterDecision::Keep;
    }
}
