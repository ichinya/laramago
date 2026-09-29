<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

/** Parse only the bounded array/scalar subset printed in native diagnostics. */
final class DiagnosticArrayTypes
{
    public static function parse(string $expression, int $depth = 0): ?Type
    {
        if ($depth > 12 || strlen($expression) > 16384) {
            return null;
        }
        $parts = self::split($expression, '|');
        if ($parts === null) {
            return null;
        }
        if (count($parts) > 1) {
            $result = null;
            foreach ($parts as $part) {
                $type = self::parse($part, $depth + 1);
                if ($type === null) {
                    return null;
                }
                $result = $result === null ? $type : Type::union($result, $type);
            }
            return $result;
        }
        $simple = match ($expression) {
            'int' => Type::int(),
            'float' => Type::float(),
            'numeric' => Type::fromAtomic(new ScalarType(ScalarTypeKind::Numeric)),
            'numeric-string' => Type::fromAtomic(new ScalarType(ScalarTypeKind::String, new StringType(
                StringLiteralKind::General, null, true, false, false, false, StringCasing::Unspecified,
            ))),
            'string' => Type::string(),
            'bool' => Type::bool(),
            'true' => Type::true(),
            'false' => Type::false(),
            'null' => Type::null(),
            'mixed' => Type::mixed(),
            'never' => Type::never(),
            'non-negative-int' => Type::nonNegativeInt(),
            'non-empty-string' => Type::nonEmptyString(),
            default => null,
        };
        if ($simple !== null) {
            return $simple;
        }
        if (preg_match('/^int\((-?(?:0|[1-9][0-9]*))\)$/D', $expression, $match)
            && (string) (int) $match[1] === $match[1]) {
            return Type::literalInt((int) $match[1]);
        }
        if (preg_match("/^string\\('([^'\\\\]*)'\\)$/D", $expression, $match)) {
            return Type::literalString($match[1]);
        }
        if (preg_match('/^(array|non-empty-array)<(.*)>$/sD', $expression, $match)) {
            $arguments = self::split($match[2], ',');
            if ($arguments === null || count($arguments) !== 2) {
                return null;
            }
            $key = self::parse($arguments[0], $depth + 1);
            $value = self::parse($arguments[1], $depth + 1);
            return $key !== null && $value !== null
                ? Type::fromAtomic(new KeyedArrayType(null, $key, $value, $match[1] === 'non-empty-array')) : null;
        }
        if (preg_match('/^(list|non-empty-list)<(.*)>$/sD', $expression, $match)) {
            $value = self::parse($match[2], $depth + 1);
            return $value === null ? null
                : Type::fromAtomic(new ListType($value, null, null, $match[1] === 'non-empty-list'));
        }
        if (! str_starts_with($expression, 'array{') || ! str_ends_with($expression, '}')) {
            return null;
        }
        $parts = self::split(substr($expression, 6, -1), ',');
        if ($parts === null || count($parts) > 128) {
            return null;
        }
        $items = [];
        $keyType = $valueType = null;
        $required = false;
        $seen = [];
        foreach ($parts as $index => $part) {
            if ($part === '...' || str_starts_with($part, '...<')) {
                if ($index !== count($parts) - 1) {
                    return null;
                }
                if ($part === '...') {
                    $keyType = Type::union(Type::int(), Type::string());
                    $valueType = Type::mixed();
                } elseif (str_ends_with($part, '>')) {
                    $fallback = self::parse('array'.substr($part, 3), $depth + 1)?->atomicTypes[0] ?? null;
                    if (! $fallback instanceof KeyedArrayType) {
                        return null;
                    }
                    $keyType = $fallback->keyType;
                    $valueType = $fallback->valueType;
                } else {
                    return null;
                }
                continue;
            }
            $pair = self::split($part, ':');
            if ($pair === null || count($pair) !== 2) {
                return null;
            }
            $optional = str_ends_with($pair[0], '?');
            $rawKey = $optional ? substr($pair[0], 0, -1) : $pair[0];
            if (preg_match("/^'([A-Za-z_][A-Za-z0-9_-]*)'$/D", $rawKey, $match)) {
                $key = new ArrayKey(ArrayKeyKind::String, $match[1]);
            } elseif (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $rawKey)
                && (string) (int) $rawKey === $rawKey) {
                $key = new ArrayKey(ArrayKeyKind::Integer, (int) $rawKey);
            } else {
                return null;
            }
            $id = self::key($key);
            $value = self::parse($pair[1], $depth + 1);
            if ($value === null || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $required = $required || ! $optional;
            $items[] = new ArrayItem($key, $optional, $value);
        }
        return Type::fromAtomic(new KeyedArrayType($items, $keyType, $valueType, $required));
    }

    /** Expand named aliases from their authoritative declaring-class metadata only. */
    public static function resolveAliases(Type $type, Codebase $codebase): ?Type
    {
        $budget = 1024;
        return self::resolve($type, $codebase, [], 0, $budget);
    }

    /** @param array<string, true> $visited */
    private static function resolve(Type $type, Codebase $codebase, array $visited, int $depth, int &$budget): ?Type
    {
        if ($depth > 16 || --$budget < 0) {
            return null;
        }
        $atoms = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof AliasType) {
                $key = strtolower($atom->class).'::'.$atom->alias;
                if (isset($visited[$key])) {
                    return null;
                }
                $metadata = $codebase->getClass($atom->class)?->typeAliases[$atom->alias] ?? null;
                if ($metadata === null) {
                    return null;
                }
                $expanded = self::resolve($metadata->type, $codebase, $visited + [$key => true], $depth + 1, $budget);
                if ($expanded === null) {
                    return null;
                }
                array_push($atoms, ...$expanded->atomicTypes);
            } elseif ($atom instanceof KeyedArrayType) {
                $items = $atom->knownItems === null ? null : [];
                foreach ($atom->knownItems ?? [] as $item) {
                    $value = self::resolve($item->type, $codebase, $visited, $depth + 1, $budget);
                    if ($value === null) {
                        return null;
                    }
                    $items[] = new ArrayItem($item->key, $item->optional, $value);
                }
                $key = $atom->keyType === null ? null : self::resolve($atom->keyType, $codebase, $visited, $depth + 1, $budget);
                $value = $atom->valueType === null ? null : self::resolve($atom->valueType, $codebase, $visited, $depth + 1, $budget);
                if ($atom->keyType !== null && ($key === null || $value === null)) {
                    return null;
                }
                $atoms[] = new KeyedArrayType($items, $key, $value, $atom->nonEmpty);
            } elseif ($atom instanceof ListType) {
                $element = self::resolve($atom->elementType, $codebase, $visited, $depth + 1, $budget);
                if ($element === null) {
                    return null;
                }
                $items = $atom->knownElements === null ? null : [];
                foreach ($atom->knownElements ?? [] as $item) {
                    $value = self::resolve($item->type, $codebase, $visited, $depth + 1, $budget);
                    if ($value === null) {
                        return null;
                    }
                    $items[] = new ListElement($item->index, $item->optional, $value);
                }
                $atoms[] = new ListType($element, $items, $atom->knownCount, $atom->nonEmpty);
            } else {
                $atoms[] = $atom;
            }
        }
        return Type::fromAtomics(...$atoms)->withFlags($type->flags);
    }

    /** Ignore extra record keys only when the complete required contract is proven. */
    public static function contains(Type $found, Type $expected, TypeComparator $types, bool &$relaxed, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }
        foreach ($found->atomicTypes as $actual) {
            $accepted = false;
            foreach ($expected->atomicTypes as $target) {
                $branchRelaxed = false;
                if (self::atomContains($actual, $target, $types, $branchRelaxed, $depth + 1)) {
                    $accepted = true;
                    $relaxed = $relaxed || $branchRelaxed;
                    break;
                }
            }
            if (! $accepted) {
                return false;
            }
        }
        return true;
    }

    /** Metadata and the diagnostic must describe the same type, including record keys. */
    public static function same(Type $left, Type $right, TypeComparator $types): bool
    {
        $relaxed = false;
        return self::contains($left, $right, $types, $relaxed)
            && self::contains($right, $left, $types, $relaxed) && ! $relaxed;
    }

    private static function atomContains(AtomicType $actual, AtomicType $target, TypeComparator $types, bool &$relaxed, int $depth): bool
    {
        if ($actual instanceof MixedType && ! $target instanceof MixedType) {
            return false;
        }
        if ($actual instanceof KeyedArrayType && $target instanceof KeyedArrayType) {
            if ($target->nonEmpty && ! $actual->nonEmpty) {
                return false;
            }
            $items = [];
            foreach ($actual->knownItems ?? [] as $item) {
                $items[self::key($item->key)] = $item;
            }
            foreach ($target->knownItems ?? [] as $item) {
                $key = self::key($item->key);
                $found = $items[$key] ?? null;
                if ($found === null) {
                    if (! $item->optional || $actual->keyType !== null) {
                        return false;
                    }
                    continue;
                }
                if ((! $item->optional && $found->optional)
                    || ! self::contains($found->type, $item->type, $types, $relaxed, $depth)) {
                    return false;
                }
                unset($items[$key]);
            }
            if ($target->keyType === null) {
                $relaxed = $relaxed || $items !== [] || $actual->keyType !== null;
                return true;
            }
            foreach ($items as $item) {
                if ($item->key->kind === ArrayKeyKind::ClassLikeConstant) {
                    return false;
                }
                $key = $item->key->kind === ArrayKeyKind::String
                    ? Type::literalString($item->key->value) : Type::literalInt($item->key->value);
                if (! self::contains($key, $target->keyType, $types, $relaxed, $depth)
                    || ! self::contains($item->type, $target->valueType, $types, $relaxed, $depth)) {
                    return false;
                }
            }
            return $actual->keyType === null
                || self::contains($actual->keyType, $target->keyType, $types, $relaxed, $depth)
                    && self::contains($actual->valueType, $target->valueType, $types, $relaxed, $depth);
        }
        if ($actual instanceof ListType && $target instanceof ListType) {
            if ($target->knownElements !== null || $target->knownCount !== null || $actual->knownElements !== null
                || $actual->knownCount !== null || $target->nonEmpty && ! $actual->nonEmpty) {
                return false;
            }
            return self::contains($actual->elementType, $target->elementType, $types, $relaxed, $depth);
        }
        return $types->isContainedBy(Type::fromAtomic($actual), Type::fromAtomic($target));
    }

    private static function key(ArrayKey $key): string
    {
        return $key->kind->name.':'.$key->value;
    }

    /** @return list<string>|null */
    private static function split(string $expression, string $delimiter): ?array
    {
        $parts = [];
        $stack = [];
        $quote = null;
        $escape = false;
        $start = 0;
        $closers = ['<' => '>', '{' => '}', '(' => ')', '[' => ']'];
        for ($index = 0, $length = strlen($expression); $index < $length; ++$index) {
            $char = $expression[$index];
            if ($quote !== null) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif (isset($closers[$char])) {
                $stack[] = $closers[$char];
                if (count($stack) > 16) {
                    return null;
                }
            } elseif (in_array($char, $closers, true)) {
                if (array_pop($stack) !== $char) {
                    return null;
                }
            } elseif ($char === $delimiter && $stack === []) {
                $parts[] = trim(substr($expression, $start, $index - $start));
                $start = $index + 1;
            }
        }
        $parts[] = trim(substr($expression, $start));
        return $stack === [] && $quote === null && ! in_array('', $parts, true) ? $parts : null;
    }
}
