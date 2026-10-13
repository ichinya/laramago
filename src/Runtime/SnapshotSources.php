<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Digested input files and directory membership required for freshness checks. */
final class SnapshotSources
{
    public static function validate(array $sources, array $sets, string $root): void
    {
        SnapshotCollection::validate(
            $sources,
            RuntimeSchemaSnapshot::MAX_SOURCES,
            SnapshotPath::absolute(...),
            SnapshotValue::digest(...),
        );
        $configSet = false;
        foreach ($sets as $set) {
            SnapshotSourceSet::validate($set, $sources);
            if (($set['directory'] ?? null) === $root . '/config' && ($set['pattern'] ?? null) === '*.php') {
                $configSet = true;
            }
        }
        SnapshotValue::require($configSet);
    }
}
