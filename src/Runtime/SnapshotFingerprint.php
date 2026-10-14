<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Stable fingerprints sort maps recursively while retaining all list ordering. */
final class SnapshotFingerprint
{
    public static function of(array $snapshot): string
    {
        return hash('sha256', json_encode(self::sorted($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $value[$key] = self::sorted($item);
        }

        return $value;
    }
}
