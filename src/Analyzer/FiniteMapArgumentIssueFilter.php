<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\FiniteMapArgumentProofs;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Correct only the certified first constructor argument of a fresh finite mapper. */
final class FiniteMapArgumentIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly FiniteMapArgumentProofs $provenance = new FiniteMapArgumentProofs) {}
    public function getCodes(): array { return ['possibly-invalid-argument']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($issue->code !== 'possibly-invalid-argument' || $issue->level !== Level::Error || count($issue->annotations) !== 2
            || count($issue->notes) !== 1 || $issue->edits !== [] || $issue->link !== null
            || $issue->help !== 'Ensure the argument always has the expected type using checks or assertions.') { return IssueFilterDecision::Keep; }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $secondary->kind !== AnnotationKind::Secondary
            || $primary->file !== null && $primary->file !== '' || $secondary->file !== null && $secondary->file !== ''
            || $secondary->message !== 'Arguments to this method are incorrect') { return IssueFilterDecision::Keep; }
        foreach ($this->provenance->proofs($context->file, $context->contents) as $proof) {
            if ($primary->span->start !== $proof['argument']->value->getStartFilePos() || $primary->span->end !== $proof['argument']->value->getEndFilePos() + 1
                || $secondary->span->start !== $proof['new']->getStartFilePos() || $secondary->span->end !== $proof['new']->getEndFilePos() + 1) { continue; }
            $certificate = $this->provenance->certificate($context->codebase, $context->types, $proof, $context->file);
            if ($certificate === null) { continue; }
            $expected = $certificate['expectedText'];
            $actual = $certificate['actualText'];
            if ($issue->message === 'Possible argument type mismatch for argument #'.$certificate['position'].' of `'.$certificate['consumer'].'`: expected `'.$expected.'`, but possibly received `'.$actual.'`.'
                && $issue->notes[0] === 'The provided type `'.$actual.'` overlaps with `'.$expected.'` but is not fully contained.'
                && $primary->message === 'This might not be type `'.$expected.'`'
                && $context->types->isContainedBy($certificate['input'], $certificate['expected'])) { return IssueFilterDecision::Remove; }
        }
        return IssueFilterDecision::Keep;
    }
}
