<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use ReflectionClass;

/** Capture execution dependencies before model construction and cast resolution. */
final class CollectionSources
{
    public static function file(CollectionState $state, string $file): void
    {
        $file = str_replace('\\', replace: '/', subject: $file);
        if (
            count($state->snapshot['sources']) >= RuntimeSchemaSnapshot::MAX_SOURCES
            && ($state->snapshot['sources'][$file] ?? null) === null
        ) {
            $state->snapshot['truncated'] = true;
            $state->error('runtime-source-limit', 'Runtime source collection limit exceeded.');
            return;
        }
        $digest = RuntimeFiles::digest($file);
        if (!is_string($digest)) {
            $state->error('runtime-source-failed', 'A runtime source could not be read.');
            return;
        }
        if (($state->snapshot['sources'][$file] ?? null) !== null && $state->snapshot['sources'][$file] !== $digest) {
            $state->error('runtime-source-changed', 'Runtime inputs changed during collection.');
        }
        $state->snapshot['sources'][$file] = $digest;
    }

    public static function hierarchy(CollectionState $state, ReflectionClass $class): void
    {
        $file = $class->getFileName();
        if (is_string($file)) {
            self::file($state, $file);
        }
        foreach ($class->getTraits() as $trait) {
            self::hierarchy($state, $trait);
        }
        $parent = $class->getParentClass();
        if ($parent !== false) {
            self::hierarchy($state, $parent);
        }
    }

    public static function included(CollectionState $state): void
    {
        foreach (get_included_files() as $file) {
            $canonical = RuntimeSchemaSnapshot::canonical($file);
            if ($canonical !== null && str_starts_with(strtolower($canonical), strtolower($state->plan->root . '/'))) {
                self::file($state, $canonical);
            }
        }
    }
}
