<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Locates literal rule names without evaluating application expressions. */
final class LiteralValidationRules
{
    /** @return list<array{string, Node\Scalar\String_}> */
    public static function from(Node\Expr $rules): array
    {
        if (! $rules instanceof Node\Expr\Array_) {
            return [];
        }
        $names = [];
        $effective = [];
        // A dynamic key or unpack can replace any earlier declaration.
        foreach ($rules->items as $field) {
            if (
                $field->unpack
                || $field->byRef
                || ! $field->key instanceof Node\Scalar\String_
            ) {
                return [];
            }
            $effective[$field->key->value] = $field->value;
        }
        foreach ($effective as $value) {
            array_push($names, ...self::fromValue($value));
        }

        return $names;
    }

    /** @return list<array{string, Node\Scalar\String_}> */
    public static function fromValue(Node\Expr $value): array
    {
        $names = [];
        if ($value instanceof Node\Scalar\String_) {
            foreach (explode('|', $value->value) as $rule) {
                self::append($names, $rule, $value);
            }

            return $names;
        }
        if (! $value instanceof Node\Expr\Array_) {
            return [];
        }
        foreach ($value->items as $item) {
            if ($item->unpack || $item->byRef || $item->key !== null) {
                return [];
            }
        }
        foreach ($value->items as $item) {
            if ($item->value instanceof Node\Scalar\String_) {
                // Array elements are individual rules. Pipes can be regex content.
                self::append($names, $item->value->value, $item->value);
            }
        }

        return $names;
    }

    /** @param list<array{string, Node\Scalar\String_}> $names */
    private static function append(array &$names, string $rule, Node\Scalar\String_ $source): void
    {
        $name = trim(explode(':', $rule, 2)[0]);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) === 1) {
            $names[] = [$name, $source];
        }
    }
}
