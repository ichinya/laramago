<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Conservative, unique corrections within one proven-complete source array. */
final class ConfigurationNameFixes
{
    public static function closest(ConfigurationKeyCatalog $catalog, string $name): ?string
    {
        if (
            ! $catalog->sourceComplete
            || $catalog->confidence($name) !== MetadataConfidence::CompleteAbsent
            || strlen($name) < 2
            || strlen($name) > 128
            || ! self::printable($name)
        ) {
            return null;
        }

        // A case-only difference is too easy to mistake for intentional syntax.
        foreach ($catalog->keys as $key) {
            if (strcasecmp($key, $name) === 0) {
                return null;
            }
        }

        $limit = strlen($name) < 12 ? 1 : 2;
        $closest = null;
        $distance = $limit + 1;
        $ambiguous = false;
        foreach ($catalog->keys as $key) {
            if (
                str_contains($key, '.')
                || strlen($key) < 2
                || strlen($key) > 128
                || ! self::printable($key)
                || abs(strlen($key) - strlen($name)) > $limit
            ) {
                continue;
            }
            $candidateDistance = self::distance($name, $key);
            if ($candidateDistance > $limit || $candidateDistance > $distance) {
                continue;
            }
            if ($candidateDistance === $distance) {
                $ambiguous = true;
                continue;
            }
            $closest = $key;
            $distance = $candidateDistance;
            $ambiguous = false;
        }

        return $ambiguous ? null : $closest;
    }

    private static function printable(string $value): bool
    {
        return preg_match('/^[\x20-\x7e]+$/D', $value) === 1;
    }

    private static function distance(string $from, string $to): int
    {
        $distance = levenshtein($from, $to);
        if ($distance <= 1 || strlen($from) !== strlen($to)) {
            return $distance;
        }
        for ($offset = 0, $length = strlen($from) - 1; $offset < $length; $offset++) {
            if (
                $from[$offset] === $to[$offset + 1]
                && $from[$offset + 1] === $to[$offset]
                && substr($from, 0, $offset) === substr($to, 0, $offset)
                && substr($from, $offset + 2) === substr($to, $offset + 2)
            ) {
                return 1;
            }
        }

        return $distance;
    }
}
