<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Version, ownership and completion rules for a collector response. */
final class SnapshotEnvelope
{
    public static function validate(array $snapshot, string $root): void
    {
        $canonical = SnapshotPath::canonical($root);
        SnapshotValue::require($canonical !== null);
        SnapshotFields::validate($snapshot, [
            'schemaVersion' => static fn(mixed $v): bool => $v === 1,
            'projectRoot' => static fn(mixed $v): bool => $v === $canonical,
            'scope' => static fn(mixed $v): bool => $v === [
                'kind' => 'runtime-schema',
                'evidence' => 'evaluated-schema',
            ],
            'truncated' => is_bool(...),
            'models' => static fn(mixed $v): bool => SnapshotCollection::bounded($v, RuntimeSchemaSnapshot::MAX_MODELS),
            'plan' => is_array(...),
            'sources' => static fn(mixed $v): bool => SnapshotCollection::bounded(
                $v,
                RuntimeSchemaSnapshot::MAX_SOURCES,
            ),
            'sourceSets' => static fn(mixed $v): bool => SnapshotCollection::list($v, 32),
            'errors' => static fn(mixed $v): bool => SnapshotCollection::list($v, 256),
        ]);
    }

    public static function complete(array $snapshot): void
    {
        if ($snapshot['errors'] === [] && !$snapshot['truncated']) {
            SnapshotValue::require(count($snapshot['models']) === count($snapshot['plan']['models']));
        }
    }
}
