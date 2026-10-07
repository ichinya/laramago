<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Correct only a proven native weak float callable boundary; never a property contract. */
final class WeakNumericFloatCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    public readonly WeakNumericFloatCalls $source;
    public function __construct(string $root = '.') { $this->source = new WeakNumericFloatCalls($root); }
    public function initialize(InitializationContext $context): void { $this->source->reset(); }
    public function getCodes(): array { return ['invalid-argument', 'invalid-return-statement']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Error || $issue->edits !== [] || $issue->link !== null
            || strlen($context->contents) > 1024 * 1024 || strlen($issue->message) > 4096) { return IssueFilterDecision::Keep; }
        if ($issue->code === 'invalid-argument'
            && preg_match('/^Invalid argument type for argument #([1-9][0-9]*) of `([^`]+)`: expected `float`, but found `([^`]+)`\.$/D', $issue->message, $match) === 1) {
            $found = $match[3];
            if (! self::domain($found) || count($issue->annotations) !== 2
                || $issue->notes !== ['The provided type `'.$found.'` is not compatible with the expected type `float`.']
                || $issue->help !== "Change the argument value to match `float`, or update the parameter's type declaration.") { return IssueFilterDecision::Keep; }
            [$primary, $secondary] = $issue->annotations;
            $method = str_contains($match[2], '::');
            if (! self::annotation($primary, AnnotationKind::Primary, 'This has type `'.$found.'`', strlen($context->contents))
                || ! self::annotation($secondary, AnnotationKind::Secondary, 'Arguments to this '.($method ? 'method' : 'function').' are incorrect', strlen($context->contents))) { return IssueFilterDecision::Keep; }
            return $this->source->argumentSite($context, $match[2], (int) $match[1] - 1, $primary->span->start, $primary->span->end,
                $secondary->span->start, $secondary->span->end) === null ? IssueFilterDecision::Keep : IssueFilterDecision::Remove;
        }
        if ($issue->code === 'invalid-return-statement'
            && preg_match('/^Invalid return type for function `([^`]+)`: expected `float`, but found `([^`]+)`\.$/D', $issue->message, $match) === 1) {
            $found = $match[2];
            if (! self::domain($found) || count($issue->annotations) !== 1
                || $issue->notes !== ['The type `'.$found.'` returned here is not compatible with the declared return type `float`.']
                || $issue->help !== "Change the return value to match `float`, or update the function's return type declaration.") { return IssueFilterDecision::Keep; }
            $primary = $issue->annotations[0];
            if (! self::annotation($primary, AnnotationKind::Primary, 'This has type `'.$found.'`', strlen($context->contents))) { return IssueFilterDecision::Keep; }
            return $this->source->returnSite($context, $match[1], $primary->span->start, $primary->span->end) === null ? IssueFilterDecision::Keep : IssueFilterDecision::Remove;
        }
        return IssueFilterDecision::Keep;
    }

    /** Native numeric-string is the only newly converted atom; existing float atoms stay compatible. */
    public static function domain(string $found): bool
    {
        if (strlen($found) > 512) { return false; }
        $parts = explode('|', $found); $numeric = false; $seen = [];
        if (count($parts) > 16) { return false; }
        foreach ($parts as $part) {
            if (isset($seen[$part])) { return false; } $seen[$part] = true;
            if ($part === 'numeric-string') { $numeric = true; continue; }
            if ($part === 'float' || preg_match('/^float\(-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\)$/D', $part) === 1) { continue; }
            return false;
        }
        return $numeric;
    }
    private static function annotation(object $annotation, AnnotationKind $kind, string $message, int $bytes): bool
    {
        return $annotation->kind === $kind && $annotation->message === $message && ($annotation->file === null || $annotation->file === '')
            && $annotation->span->start >= 0 && $annotation->span->end > $annotation->span->start && $annotation->span->end <= $bytes;
    }
}
