<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Compatibility policy for a classified, defensive configuration validator. */
final class DefensiveConfigurationMemberFilter implements IssueFilterHook
{
    public function __construct(public readonly DefensiveBoundaryGuardProof $proofs) {}

    public function getCodes(): array
    {
        return ['impossible-nonnull-entry-check', 'impossible-type-comparison'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || count($issue->annotations) !== 1 || count($issue->notes) !== 1
            || $issue->link !== null || $issue->edits !== []) {
            return IssueFilterDecision::Keep;
        }
        $primary = $issue->annotations[0];
        if ($primary->kind !== AnnotationKind::Primary || $primary->file !== null && $primary->file !== ''
            || $primary->span->start < 0 || $primary->span->end <= $primary->span->start
            || $primary->span->end > strlen($context->contents)) {
            return IssueFilterDecision::Keep;
        }
        $sites = DefensiveConfigurationMemberSource::compile(DefensiveBoundaryGuardSource::parse($context->contents));
        $site = $sites[$primary->span->start.':'.$primary->span->end] ?? null;
        if ($site === null || !self::envelope($context, $site)) {
            return IssueFilterDecision::Keep;
        }
        $proof = $this->proofs->proveConfigurationGuard($context, $site['predicate']);

        return $proof['remove'] ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    private static function envelope(IssueFilterContext $context, array $site): bool
    {
        $issue = $context->issue;
        $primary = $issue->annotations[0];
        $field = "'".$site['field']."'";
        $record = '$'.$site['record'];
        $value = '$'.$site['value'];
        if ($site['kind'] === 'key' && $issue->level === Level::Warning
            && $issue->code === 'impossible-nonnull-entry-check') {
            $prefix = 'Impossible `isset` check on key `'.$field.'` accessed on `';
            $type = str_starts_with($issue->message, $prefix) && str_ends_with($issue->message, '`.')
                ? substr($issue->message, strlen($prefix), -2) : null;

            return $type !== null && strlen($type) <= 16384 && str_starts_with($type, 'array{') && str_ends_with($type, '}')
                && $issue->notes === ['The analysis determined that the key `'.$field.'` definitely does not exist in this array, so checking `isset` is unnecessary.']
                && $issue->help === 'Remove the redundant `isset` check.'
                && $primary->message === '`isset` on key `'.$field.'` will always be false here.';
        }
        if ($site['kind'] === 'coalesce' && $issue->level === Level::Warning
            && $issue->code === 'impossible-nonnull-entry-check') {
            return $issue->message === 'Impossible condition: variable `'.$record.'` can never have a non-null entry for key `'.$field.'`.'
                && $issue->notes === ['Variable `'.$record.'` is known to either not have the key `'.$field.'` or its value is always `null`. This check for a non-null entry will always be false.']
                && $issue->help === 'Verify the array/object structure or remove this `!empty()` style check.'
                && $primary->message === 'This condition always evaluates to false';
        }
        if ($site['kind'] === 'predicate' && $issue->level === Level::Error
            && $issue->code === 'impossible-type-comparison') {
            return $issue->message === 'Impossible type assertion: `'.$value.'` of type `null` can never be `array<array-key, mixed>`.'
                && $issue->notes === ['The assertion expects `'.$value.'` to be `array<array-key, mixed>`, but no value of type `null` can satisfy this.']
                && $issue->help === 'Check that the correct variable is being passed, or update the assertion type.'
                && $primary->message === 'Argument `'.$value.'` has type `null`';
        }

        return false;
    }
}
