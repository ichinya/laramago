<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Dependency-free validation and input revision checks for collector snapshots. */
final class RuntimeSchemaSnapshot
{
    public const MAX_BYTES = 4_194_304;
    public const MAX_MODELS = 128;
    public const MAX_SOURCES = 4096;
    public const MAX_COLUMNS = 2048;

    public static function load(string $path, string $root): array
    {
        $size = RuntimeFiles::size($path);
        SnapshotValue::require(is_int($size) && $size <= self::MAX_BYTES);
        $contents = RuntimeFiles::read($path, self::MAX_BYTES + 1);
        SnapshotValue::require(is_string($contents));

        return self::decode($contents, $root);
    }

    public static function decode(string $contents, string $root): array
    {
        SnapshotValue::require(strlen($contents) <= self::MAX_BYTES);
        try {
            $snapshot = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            SnapshotValue::require(false);
        }
        SnapshotValue::require(is_array($snapshot));

        return self::validate($snapshot, $root);
    }

    public static function validate(array $snapshot, string $root): array
    {
        SnapshotEnvelope::validate($snapshot, $root);
        SnapshotPlan::validate($snapshot['plan']);
        SnapshotModels::validate($snapshot['models'], $snapshot['plan']['models']);
        SnapshotSources::validate($snapshot['sources'], $snapshot['sourceSets'], $snapshot['projectRoot']);
        SnapshotErrors::validate($snapshot['errors']);
        SnapshotEnvelope::complete($snapshot);

        return $snapshot;
    }

    public static function currentInputs(array $snapshot): bool
    {
        return SnapshotInputs::current($snapshot);
    }

    public static function fingerprint(array $snapshot): string
    {
        return SnapshotFingerprint::of($snapshot);
    }

    public static function canonical(string $path): ?string
    {
        return SnapshotPath::canonical($path);
    }
}
