<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Classify static default attributes without invoking callback defaults. */
final class RelationDefaults
{
    public static function enabled(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return null;
        }
        if (count($value) === 2 && array_key_exists(0, $value) && array_key_exists(1, $value) && is_string($value[1])) {
            return null;
        }

        return $value !== [];
    }
}
