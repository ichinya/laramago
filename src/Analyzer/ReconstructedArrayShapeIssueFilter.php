<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReconstructedArrayShapes;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Repair only the constructor argument whose rebuilt primitive discriminator correlation was lost. */
final class ReconstructedArrayShapeIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly ReconstructedArrayShapes $provenance = new ReconstructedArrayShapes) {}

    public function getCodes(): array { return ['less-specific-nested-argument-type']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($issue->code !== 'less-specific-nested-argument-type' || $issue->level !== Level::Error || strlen($issue->message) > 32768
            || count($issue->annotations) !== 2 || count($issue->notes) !== 2 || $issue->edits !== []
            || $issue->notes[0] !== 'The structure contains `mixed`, making it incompatible.'
            || ! preg_match('/^Argument type mismatch for argument #([1-9][0-9]*) of `([^`]+)::__construct`: expected `([^`]+)`, but provided type `([^`]+)` is less specific\.$/sD', $issue->message, $match)) { return IssueFilterDecision::Keep; }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $secondary->kind !== AnnotationKind::Secondary
            || $primary->file !== null && $primary->file !== '' || $secondary->file !== null && $secondary->file !== ''
            || $primary->message !== 'Provided type `'.$match[4].'` is too general due to nested `mixed`.'
            || $secondary->message !== 'Arguments to this method are incorrect'
            || $issue->help !== 'Provide a value that more precisely matches `'.$match[3].'` or adjust the parameter type.') { return IssueFilterDecision::Keep; }
        $expected = DiagnosticArrayTypes::parse($match[3]);
        $provided = DiagnosticArrayTypes::parse($match[4]);
        if ($expected === null || $provided === null) { return IssueFilterDecision::Keep; }
        foreach ($this->provenance->proofs($context->file, $context->contents) as $proof) {
            if ($proof['position'] !== (int) $match[1] - 1 || strcasecmp($proof['owner'], $match[2]) !== 0
                || ReconstructedArrayShapes::key($proof['argument']->value) !== $primary->span->start.':'.$primary->span->end
                || ReconstructedArrayShapes::key($proof['call']) !== $secondary->span->start.':'.$secondary->span->end
                || ! self::difference($issue->notes[1], $proof['identifier'])
                || ! ReconstructedArrayShapes::current($proof) || ! ReconstructedArrayShapes::valid($context->codebase, $proof)) { continue; }
            $signature = $context->codebase->getMethod($proof['owner'], '__construct');
            $parameter = $signature?->parameters[$proof['position']] ?? null;
            $declared = $parameter?->type?->type;
            $declared = $declared === null ? null : DiagnosticArrayTypes::resolveAliases($declared, $context->codebase);
            if ($declared !== null && DiagnosticArrayTypes::same($expected, $declared, $context->types)
                && DiagnosticArrayTypes::same($provided, $proof['generalShape'], $context->types)
                && $context->types->isContainedBy($proof['shape'], $declared)) { return IssueFilterDecision::Remove; }
        }
        return IssueFilterDecision::Keep;
    }

    /** Native diagnostic diffs must contain exactly the independently proved integer-to-mixed change. */
    private static function difference(string $difference, string $identifier): bool
    {
        if (strlen($difference) > 16384) { return false; }
        $lines = explode("\n", $difference);
        if (array_shift($lines) !== '--- original' || array_shift($lines) !== '+++ modified'
            || preg_match('/^@@ -[0-9]+,[0-9]+ \+[0-9]+,[0-9]+ @@$/D', array_shift($lines) ?? '') !== 1) { return false; }
        $removed = $added = [];
        foreach ($lines as $line) {
            if ($line === '') { continue; }
            if ($line[0] === '-') { $removed[] = $line; }
            elseif ($line[0] === '+') { $added[] = $line; }
            elseif ($line[0] !== ' ') { return false; }
        }
        return $removed === ["-  '".$identifier."': int,"] && $added === ["+  '".$identifier."': mixed,"];
    }
}
