<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticReturnTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedYieldedLists;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;

/** Correct only a lost exhaustive element proof; never replace the declared yield contract. */
final class ValidatedYieldedListIssueFilter implements IssueFilterHook
{
    public function __construct(public readonly ValidatedYieldedLists $proofs = new ValidatedYieldedLists) {}

    public function getCodes(): array { return ['invalid-yield-value-type']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->code !== 'invalid-yield-value-type' || $issue->level !== Level::Error
            || strlen($issue->message) > 16384 || count($issue->annotations) !== 1 || count($issue->notes) !== 2
            || $issue->notes[0] !== "The type of the value yielded must be assignable to the value type declared in the Generator's return type hint."
            || strlen($issue->notes[1]) > 32768 || ! str_starts_with($issue->notes[1], "--- original\n+++ modified\n@@ ")
            || $issue->help !== "Ensure the yielded value matches the expected type, or adjust the Generator's return type hint."
            || $issue->link !== null || $issue->edits !== []
            || preg_match('/^Invalid value type yielded; expected `([^`]+)`, but found `([^`]+)`\.$/D', $issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $primary = $issue->annotations[0];
        $expected = DiagnosticReturnTypes::parse($match[1]);
        $found = DiagnosticReturnTypes::parse($match[2]);
        if ($expected === null || $found === null || ! self::diff($match[1], $match[2], $issue->notes[1]) || $primary->kind !== AnnotationKind::Primary
            || $primary->file !== null && $primary->file !== '' || $primary->message !== 'This expression yields type `'.$match[2].'`'
            || $primary->span->start < 0 || $primary->span->end <= $primary->span->start
            || $primary->span->end > strlen($context->contents)) { return IssueFilterDecision::Keep; }
        foreach ($this->proofs->proofs($context->file, $context->contents) as $proof) {
            if ($proof['value']->getStartFilePos() !== $primary->span->start
                || $proof['value']->getEndFilePos() + 1 !== $primary->span->end) { continue; }
            $declared = $this->proofs->contract($context->codebase, $context->types, $proof);
            $proven = self::narrow($found, $proof['field'], $context);
            if ($declared !== null && $proven !== null && DiagnosticArrayTypes::same($declared, $expected, $context->types)
                && $context->types->isContainedBy($proven, $expected)) { return IssueFilterDecision::Remove; }
        }
        return IssueFilterDecision::Keep;
    }

    private static function narrow(Type $found, string $field, IssueFilterContext $context): ?Type
    {
        $wrapper = count($found->atomicTypes) === 1 ? $found->atomicTypes[0] : null;
        if (! $wrapper instanceof ListType || $wrapper->knownCount !== 1 || ! $wrapper->nonEmpty
            || count($wrapper->knownElements ?? []) !== 1 || $wrapper->knownElements[0]->index !== 0
            || $wrapper->knownElements[0]->optional) { return null; }
        $recordType = $wrapper->knownElements[0]->type;
        if (! $context->types->equals($wrapper->elementType, $recordType)) { return null; }
        $record = count($recordType->atomicTypes) === 1 ? $recordType->atomicTypes[0] : null;
        if (! $record instanceof KeyedArrayType || $record->knownItems === null || $record->keyType !== null || $record->valueType !== null) { return null; }
        $items = [];
        $replaced = 0;
        foreach ($record->knownItems as $item) {
            if ($item->key->kind !== ArrayKeyKind::String || $item->key->value !== $field) { $items[] = $item; continue; }
            $list = count($item->type->atomicTypes) === 1 ? $item->type->atomicTypes[0] : null;
            if (! $item->optional || ! $list instanceof ListType || $list->knownElements !== null
                || $list->knownCount !== null || $list->nonEmpty || ! $context->types->equals($list->elementType, Type::mixed())) { return null; }
            $items[] = new ArrayItem($item->key, true, Type::list(Type::string()));
            $replaced++;
        }
        if ($replaced !== 1) { return null; }
        $narrowed = Type::fromAtomic(new KeyedArrayType($items, null, null, $record->nonEmpty));
        // The proved literal contains exactly one implicit integer key. Native
        // containment does not normalize list{record} to array{0: record}.
        return Type::fromAtomic(new KeyedArrayType([new ArrayItem(new ArrayKey(ArrayKeyKind::Integer, 0), false, $narrowed)], null, null, true));
    }

    /** Verify the entire native unified diff against both reported types, including omitted equal context. */
    private static function diff(string $expected, string $found, string $diff): bool
    {
        $before = self::pretty($expected);
        $after = self::pretty($found);
        if ($before === null || $after === null || ! str_ends_with($diff, "\n")) { return false; }
        $lines = explode("\n", substr($diff, 0, -1));
        if (array_shift($lines) !== '--- original' || array_shift($lines) !== '+++ modified') { return false; }
        $old = $new = $index = $hunks = 0;
        while ($index < count($lines)) {
            if (preg_match('/^@@ -([1-9][0-9]*)(?:,([0-9]+))? \+([1-9][0-9]*)(?:,([0-9]+))? @@$/D', $lines[$index++], $match) !== 1) { return false; }
            $oldStart = (int) $match[1] - 1;
            $newStart = (int) $match[3] - 1;
            $oldCount = ($match[2] ?? '') === '' ? 1 : (int) $match[2];
            $newCount = ($match[4] ?? '') === '' ? 1 : (int) $match[4];
            if ($oldStart < $old || $newStart < $new || $oldStart - $old !== $newStart - $new
                || $oldStart + $oldCount > count($before) || $newStart + $newCount > count($after)) { return false; }
            while ($old < $oldStart) { if ($before[$old++] !== $after[$new++]) { return false; } }
            $removed = $added = $changes = 0;
            while ($index < count($lines) && ! str_starts_with($lines[$index], '@@ ')) {
                $line = $lines[$index++];
                $kind = $line[0] ?? '';
                $text = substr($line, 1);
                if ($kind === ' ' || $kind === '-') {
                    if ($removed >= $oldCount || ($before[$old++] ?? null) !== $text) { return false; }
                    $removed++;
                }
                if ($kind === ' ' || $kind === '+') {
                    if ($added >= $newCount || ($after[$new++] ?? null) !== $text) { return false; }
                    $added++;
                }
                if ($kind === '-' || $kind === '+') { $changes++; }
                elseif ($kind !== ' ') { return false; }
            }
            if ($removed !== $oldCount || $added !== $newCount || $changes === 0) { return false; }
            $hunks++;
        }
        while ($old < count($before) && $new < count($after)) { if ($before[$old++] !== $after[$new++]) { return false; } }
        return $hunks > 0 && $old === count($before) && $new === count($after);
    }

    /** @return list<string>|null */
    private static function pretty(string $expression, int $depth = 0): ?array
    {
        if ($depth > 12) { return null; }
        if (preg_match('/^(array|list)\{(.*)\}$/sD', $expression, $match) !== 1) { return [$expression]; }
        $parts = $match[2] === '' ? [] : DiagnosticArrayTypes::split($match[2], ',');
        if ($parts === null || count($parts) > 128) { return null; }
        $lines = [$match[1].'{'];
        foreach ($parts as $part) {
            $prefix = '';
            $value = $part;
            if ($match[1] === 'array') {
                $pair = DiagnosticArrayTypes::split($part, ':');
                if ($pair === null || count($pair) !== 2) { return null; }
                [$key, $value] = $pair;
                $prefix = $key.': ';
            }
            $child = self::pretty($value, $depth + 1);
            if ($child === null) { return null; }
            foreach ($child as $index => $line) { $lines[] = '  '.($index === 0 ? $prefix : '').$line.($index === count($child) - 1 ? ',' : ''); }
        }
        $lines[] = '}';
        return $lines;
    }
}
