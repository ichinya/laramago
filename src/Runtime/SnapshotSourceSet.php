<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** A bounded explicit path set or a supported directory glob, with digested members. */
final class SnapshotSourceSet
{
    public static function validate(mixed $set, array $sources): void
    {
        SnapshotFields::validate($set, [
            'files' => static fn(mixed $v): bool => SnapshotCollection::list($v, RuntimeSchemaSnapshot::MAX_SOURCES),
        ]);
        SnapshotCollection::validate(
            $set['files'],
            RuntimeSchemaSnapshot::MAX_SOURCES,
            is_int(...),
            static fn(mixed $path): bool => SnapshotPath::absolute($path) && ($sources[$path] ?? null) !== null,
        );
        if (($set['paths'] ?? null) !== null) {
            SnapshotValue::require(SnapshotCollection::list($set['paths'], RuntimeSchemaSnapshot::MAX_SOURCES));
            SnapshotCollection::validate(
                $set['paths'],
                RuntimeSchemaSnapshot::MAX_SOURCES,
                is_int(...),
                SnapshotPath::absolute(...),
            );
            return;
        }
        SnapshotFields::validate($set, [
            'directory' => SnapshotPath::absolute(...),
            'pattern' => static fn(mixed $v): bool => in_array($v, ['*.php', '.env*'], strict: true),
        ]);
    }
}
