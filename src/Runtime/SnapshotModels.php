<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Model identities and their distinct cast and SQL-column contracts. */
final class SnapshotModels
{
    public static function validate(array $models, array $plan): void
    {
        foreach ($models as $name => $model) {
            SnapshotValue::require(SnapshotValue::className($name) && in_array($name, $plan, strict: true));
            SnapshotFields::validate($model, [
                'connection' => SnapshotValue::name(...),
                'table' => SnapshotValue::name(...),
                'databaseIdentity' => SnapshotValue::digest(...),
                'casts' => static fn(mixed $v): bool => SnapshotCollection::bounded(
                    $v,
                    RuntimeSchemaSnapshot::MAX_COLUMNS,
                ),
                'columns' => static fn(mixed $v): bool => SnapshotCollection::bounded(
                    $v,
                    RuntimeSchemaSnapshot::MAX_COLUMNS,
                ),
            ]);
            SnapshotCollection::validate(
                $model['casts'],
                RuntimeSchemaSnapshot::MAX_COLUMNS,
                SnapshotValue::name(...),
                static fn(mixed $v): bool => SnapshotValue::name($v, 1024),
            );
            foreach ($model['columns'] as $column => $definition) {
                SnapshotValue::require(SnapshotValue::name($column));
                SnapshotFields::validate($definition, [
                    'typeName' => static fn(mixed $v): bool => is_string($v) && strlen($v) <= 256,
                    'nullable' => is_bool(...),
                    'strict' => is_bool(...),
                ]);
            }
        }
    }
}
