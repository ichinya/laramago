<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** An explicit list of distinct model classes and an optional autoload entry point. */
final class SnapshotPlan
{
    public static function validate(array $plan): void
    {
        SnapshotFields::validate($plan, [
            'models' => static fn(mixed $v): bool => SnapshotCollection::list($v, RuntimeSchemaSnapshot::MAX_MODELS),
        ]);
        SnapshotCollection::validate(
            $plan['models'],
            RuntimeSchemaSnapshot::MAX_MODELS,
            is_int(...),
            SnapshotValue::className(...),
        );
        SnapshotValue::require(count(array_unique($plan['models'])) === count($plan['models']));
        if (isset($plan['autoload'])) {
            SnapshotValue::require(SnapshotPath::absolute($plan['autoload']));
        }
    }
}
