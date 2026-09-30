<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;

/** Bounded diagnostic grammar for arrays containing concrete object values. */
final class DiagnosticReturnTypes
{
    public static function parse(string $expression, bool $widenLiterals = false, int $depth = 0): ?Type
    {
        if ($depth > 12 || strlen($expression) > 16384) {
            return null;
        }
        $parts = DiagnosticArrayTypes::split($expression, '|');
        if ($parts === null) {
            return null;
        }
        if (count($parts) > 1) {
            $types = [];
            foreach ($parts as $part) {
                $type = self::parse($part, $widenLiterals, $depth + 1);
                if ($type === null) {
                    return null;
                }
                $types[] = $type;
            }
            return self::union($types);
        }
        $simple = DiagnosticArrayTypes::parse($expression, $depth);
        if ($simple !== null) {
            return $simple;
        }
        // Escaped diagnostic literals are widened only on the actual-value side.
        // This cannot satisfy a literal/non-empty/numeric string requirement.
        if ($widenLiterals && preg_match("~^string\\('(?:[^'\\\\]|\\\\.)*'\\)$~sD", $expression)) {
            return Type::string();
        }
        if ($expression === 'array-key') {
            return Type::union(Type::int(), Type::string());
        }
        if (preg_match('/^(array|non-empty-array|list|non-empty-list)<(.*)>$/sD', $expression, $match)) {
            $arguments = DiagnosticArrayTypes::split($match[2], ',');
            $list = str_ends_with($match[1], 'list');
            if ($arguments === null || count($arguments) !== ($list ? 1 : 2)) {
                return null;
            }
            $value = self::parse($arguments[$list ? 0 : 1], $widenLiterals, $depth + 1);
            $key = $list ? null : self::parse($arguments[0], $widenLiterals, $depth + 1);
            if ($value === null || ! $list && $key === null) {
                return null;
            }
            $nonEmpty = str_starts_with($match[1], 'non-empty-');
            return Type::fromAtomic($list ? new ListType($value, null, null, $nonEmpty)
                : new KeyedArrayType(null, $key, $value, $nonEmpty));
        }
        if (preg_match('/^(array|list)\{(.*)\}$/sD', $expression, $match)) {
            $parts = $match[2] === '' ? [] : DiagnosticArrayTypes::split($match[2], ',');
            if ($parts === null || count($parts) > 128) {
                return null;
            }
            $items = [];
            $values = [];
            $seen = [];
            $required = false;
            foreach ($parts as $index => $part) {
                if ($match[1] === 'list') {
                    $value = self::parse($part, $widenLiterals, $depth + 1);
                    if ($value === null) {
                        return null;
                    }
                    $items[] = new ListElement($index, false, $value);
                    $values[] = $value;
                    continue;
                }
                $pair = DiagnosticArrayTypes::split($part, ':');
                if ($pair === null || count($pair) !== 2) {
                    return null;
                }
                $optional = str_ends_with($pair[0], '?');
                $rawKey = $optional ? substr($pair[0], 0, -1) : $pair[0];
                if (preg_match("/^'([A-Za-z_][A-Za-z0-9_.*:-]*)'$/D", $rawKey, $keyMatch)) {
                    $key = new ArrayKey(ArrayKeyKind::String, $keyMatch[1]);
                } elseif (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $rawKey) && (string) (int) $rawKey === $rawKey) {
                    $key = new ArrayKey(ArrayKeyKind::Integer, (int) $rawKey);
                } else {
                    return null;
                }
                $id = $key->kind->name.':'.$key->value;
                $value = self::parse($pair[1], $widenLiterals, $depth + 1);
                if ($value === null || isset($seen[$id])) {
                    return null;
                }
                $seen[$id] = true;
                $required = $required || ! $optional;
                $items[] = new ArrayItem($key, $optional, $value);
            }
            return Type::fromAtomic($match[1] === 'list'
                ? new ListType(self::union($values), $items, count($items), $items !== [])
                : new KeyedArrayType($items, null, null, $required));
        }
        if (preg_match('/^\$this\(([^()]+)\)$/D', $expression, $match)) {
            $expression = $match[1];
        }
        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $expression)
            ? Type::namedObject(ltrim($expression, '\\')) : null;
    }

    /** @param list<Type> $types */
    private static function union(array $types): Type
    {
        return array_reduce($types, static fn (Type $left, Type $right): Type => Type::union($left, $right), Type::never());
    }
}
