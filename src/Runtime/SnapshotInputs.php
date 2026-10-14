<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Rechecks both file contents and the membership of prepared source sets. */
final class SnapshotInputs
{
    public static function current(array $snapshot): bool
    {
        foreach ($snapshot['sources'] ?? [] as $path => $digest) {
            clearstatcache(true, $path);
            if (!is_file($path) || RuntimeFiles::digest($path) !== $digest) {
                return false;
            }
        }
        foreach ($snapshot['sourceSets'] ?? [] as $set) {
            if (self::members($set) !== $set['files']) {
                return false;
            }
        }

        return true;
    }

    private static function members(array $set): array
    {
        $files = ($set['paths'] ?? null) !== null
            ? array_values(array_filter($set['paths'], is_file(...)))
            : glob($set['directory'] . '/' . $set['pattern']);
        $files = $files === false ? [] : $files;
        $files = array_values(array_filter($files, is_file(...)));
        $files = array_map(static fn(string $file): string => str_replace('\\', replace: '/', subject: $file), $files);
        sort($files, SORT_STRING);

        return $files;
    }
}
