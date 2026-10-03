<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;

/** Source proof repairs only native XML cardinality/cache reports that the SDK cannot prevent. */
final class SimpleXmlProvenanceIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly SimpleXmlProvenance $provenance = new SimpleXmlProvenance) {}

    public function getCodes(): array
    {
        return ['impossible-type-comparison', 'redundant-type-comparison', 'mixed-array-access', 'mixed-property-access', 'mixed-argument'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        $primary = array_values(array_filter($issue->annotations, static fn ($annotation): bool => $annotation->kind === AnnotationKind::Primary));
        $annotationMessage = match ($issue->code) {
            'mixed-array-access' => 'Cannot safely access index because base type is `mixed`.',
            'mixed-property-access' => 'Cannot access property here',
            'mixed-argument' => 'Argument has type `mixed`',
            'impossible-type-comparison' => 'This condition always evaluates to false',
            'redundant-type-comparison' => 'This condition always evaluates to true',
            default => null,
        };
        if (count($primary) !== 1 || $annotationMessage === null || $primary[0]->message !== $annotationMessage) {
            return IssueFilterDecision::Keep;
        }
        foreach ($issue->annotations as $annotation) {
            if ($annotation->file !== null && $annotation->file !== '' || $annotation->span->start < 0
                || $annotation->span->end > strlen($context->contents)) {
                return IssueFilterDecision::Keep;
            }
        }
        $key = $primary[0]->span->start.':'.$primary[0]->span->end;
        foreach ($this->provenance->proofs($context->file, $context->contents) as $proof) {
            $match = false;
            if ($issue->code === 'mixed-array-access' && $issue->message === 'Unsafe array access on type `mixed`.') {
                $match = isset($proof['attributes'][$key]);
            } elseif ($issue->code === 'mixed-property-access' && $issue->message === 'Attempting to access a property on a non-object type (`mixed`).') {
                $property = $proof['properties'][$key] ?? null;
                $secondary = $issue->annotations[1] ?? null;
                $match = $property !== null && count($issue->annotations) === 2 && $secondary !== null
                    && $secondary->kind === AnnotationKind::Secondary && $secondary->message === 'This expression has type `mixed`'
                    && $secondary->span->start === $property->var->getStartFilePos()
                    && $secondary->span->end === $property->var->getEndFilePos() + 1;
            } elseif ($issue->code === 'mixed-argument'
                && $issue->message === 'Invalid argument type for argument #1 of `count`: expected `Countable|array<array-key, mixed>`, but found `mixed`.') {
                $call = $proof['arguments'][$key] ?? null;
                $secondary = $issue->annotations[1] ?? null;
                $match = $call !== null && (count($issue->annotations) === 1 || count($issue->annotations) === 2
                    && $secondary !== null && $secondary->kind === AnnotationKind::Secondary
                    && $secondary->message === 'Arguments to this function are incorrect'
                    && $secondary->span->start === $call->name->getStartFilePos()
                    && $secondary->span->end === $call->name->getEndFilePos() + 1);
            } elseif ($issue->code === 'impossible-type-comparison' || $issue->code === 'redundant-type-comparison') {
                $fact = $proof['comparisons'][$key] ?? null;
                $expression = $fact['expression'] ?? null;
                if ($expression !== null) {
                    if ($issue->code === 'impossible-type-comparison' && $fact['known'] === null) {
                        $match = in_array($issue->message, [
                            'Impossible condition: variable `'.$expression.'` (type `SimpleXMLElement`) can never be `has-exactly-1`.',
                            'Impossible condition: variable `'.$expression.'` (type `SimpleXMLElement|null`) can never be `has-exactly-1`.',
                        ], true);
                    } elseif ($fact['known'] === null) {
                        $match = $issue->message === 'Redundant condition: variable `'.$expression.'` (type `mixed`) is already known to be `has-exactly-1`.';
                    }
                }
            }
            if ($match && (in_array($issue->code, ['mixed-property-access', 'mixed-argument'], true) || count($issue->annotations) === 1)
                && SimpleXmlProvenance::current($proof) && SimpleXmlProvenance::valid($context->codebase, $proof)) {
                return IssueFilterDecision::Remove;
            }
        }
        return IssueFilterDecision::Keep;
    }
}
