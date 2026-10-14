<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Required field predicates; additional fields remain forward-compatible. */
final class SnapshotFields
{
    public static function validate(mixed $record, array $predicates): void
    {
        SnapshotValue::require(is_array($record));
        foreach ($predicates as $field => $predicate) {
            SnapshotValue::require(array_key_exists($field, $record));
            SnapshotValue::require($predicate($record[$field]));
        }
    }
}
