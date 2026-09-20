<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Conservative literal references in effective validation rule arrays. */
final class LiteralValidationDatabaseRules
{
    /** @return list<array{string, string, ?string, Node, ?Node}> */
    public static function from(Node\Expr $rules): array
    {
        if (! $rules instanceof Node\Expr\Array_) {
            return [];
        }
        $fields = [];
        foreach ($rules->items as $item) {
            if ($item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_) {
                return [];
            }
            $fields[$item->key->value] = [$item->key->value, $item->value];
        }
        $references = [];
        foreach ($fields as [$field, $value]) {
            array_push($references, ...self::fromValue($value, $field));
        }

        return $references;
    }

    /** @return list<array{string, string, ?string, Node, ?Node}> */
    public static function fromValue(Node\Expr $rules, string $field): array
    {
        if ($rules instanceof Node\Scalar\String_) {
            // Regex parameters may contain pipes; leave ambiguous strings unknown.
            if (preg_match('/(?:^|\|)\s*(?:regex|not_regex|notregex)\s*:/i', $rules->value) === 1) {
                return [];
            }
            $result = [];
            foreach (explode('|', $rules->value) as $part) {
                $reference = self::stringRule($part, $field, $rules);
                if ($reference !== null) {
                    $result[] = $reference;
                }
            }

            return $result;
        }
        if (! $rules instanceof Node\Expr\Array_) {
            return [];
        }
        foreach ($rules->items as $item) {
            if ($item->unpack || $item->byRef || $item->key !== null) {
                return [];
            }
        }
        $result = [];
        foreach ($rules->items as $item) {
            $rule = $item->value;
            if ($rule instanceof Node\Scalar\String_) {
                $reference = self::stringRule($rule->value, $field, $rule);
            } else {
                $reference = null;
            }
            if ($reference !== null) {
                $result[] = $reference;
            }
        }

        return $result;
    }

    /** @return array{string, string, ?string, Node, ?Node}|null */
    private static function stringRule(string $rule, string $field, Node\Scalar\String_ $node): ?array
    {
        $split = explode(':', trim($rule), 2);
        if (count($split) !== 2 || ! in_array(strtolower($split[0]), ['exists', 'unique'], true)) {
            return null;
        }
        $name = strtolower($split[0]);
        /** @var list<string|null> $parts */
        $parts = str_getcsv($split[1], escape: '\\');
        $table = $parts[0] ?? null;
        if (! is_string($table) || $table === '' || preg_match('/[\s"\'\\x00]/', $table) === 1) {
            return null;
        }
        $rawColumn = $parts[1] ?? 'NULL';
        $column = $rawColumn === 'NULL' ? $field : $rawColumn;
        if ($column === '' || str_contains($column, '*') || str_contains($column, '.')) {
            $column = null;
        }
        $ignoredId = $parts[2] ?? null;
        $rawIgnore = $parts[3] ?? null;
        $ignore =
            $name === 'unique'
            && is_string($ignoredId)
            && strtolower($ignoredId) !== 'null'
            && is_string($rawIgnore)
            && $rawIgnore !== ''
                ? $rawIgnore
                : null;

        return [$table, $column ?? '', $ignore, $node, null];
    }
}
