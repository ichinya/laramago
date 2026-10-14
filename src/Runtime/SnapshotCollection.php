<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Bounded lists and maps used throughout the snapshot format. */
final class SnapshotCollection
{
    public static function bounded(mixed $value, int $limit): bool
    {
        return is_array($value) && count($value) <= $limit;
    }

    public static function list(mixed $value, int $limit): bool
    {
        return self::bounded($value, $limit) && array_is_list($value);
    }

    public static function validate(mixed $value, int $limit, callable $key, callable $item): void
    {
        SnapshotValue::require(self::bounded($value, $limit));
        foreach ($value as $name => $entry) {
            SnapshotValue::require($key($name));
            SnapshotValue::require($item($entry));
        }
    }
}
