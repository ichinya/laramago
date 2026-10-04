<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Correct only an obsolete literal impossibility after a proved native attribute replacement. */
final class RefreshedModelPropertyIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly RefreshedModelProperties $provenance = new RefreshedModelProperties) {}

    public function getCodes(): array { return ['impossible-type-comparison']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($issue->code !== 'impossible-type-comparison' || $issue->level !== Level::Error || strlen($issue->message) > 16384
            || count($issue->annotations) !== 1 || count($issue->notes) !== 1 || $issue->edits !== [] || $issue->link !== null
            || $issue->help !== 'Check that the correct variable is being passed, or update the assertion type.'
            || preg_match('/^Impossible type assertion: `(\$[A-Za-z_][A-Za-z0-9_]*->[A-Za-z_][A-Za-z0-9_]*)` of type `([^`]+)` can never be `([^`]+)`\.$/sD', $issue->message, $match) !== 1) { return IssueFilterDecision::Keep; }
        $primary = $issue->annotations[0];
        if ($primary->kind !== AnnotationKind::Primary || $primary->file !== null && $primary->file !== ''
            || $primary->message !== 'Argument `'.$match[1].'` has type `'.$match[2].'`'
            || $issue->notes[0] !== 'The assertion expects `'.$match[1].'` to be `'.$match[3].'`, but no value of type `'.$match[2].'` can satisfy this.') { return IssueFilterDecision::Keep; }
        $old = DiagnosticArrayTypes::parse($match[2]);
        $expected = DiagnosticArrayTypes::parse($match[3]);
        if ($old === null || $expected === null) { return IssueFilterDecision::Keep; }
        foreach ($this->provenance->proofs($context->file, $context->contents) as $proof) {
            if ('$'.$proof['receiver'].'->'.$proof['property'] !== $match[1]
                || RefreshedModelProperties::key($proof['assertion']) !== $primary->span->start.':'.$primary->span->end
                || ! DiagnosticArrayTypes::same($old, RefreshedModelProperties::literal($proof['observation']['expected']), $context->types)
                || ! DiagnosticArrayTypes::same($expected, RefreshedModelProperties::literal($proof['expected']), $context->types)
                || ! $this->provenance->valid($context->codebase, $context->types, $proof)) { continue; }
            return IssueFilterDecision::Remove;
        }
        return IssueFilterDecision::Keep;
    }
}
