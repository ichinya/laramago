<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Track membership as well as content for configuration and environment inputs. */
final class CollectionSourceSets
{
    public static function capture(CollectionState $state): void
    {
        $state->snapshot['sourceSets'] = [];
        foreach ($state->sets as $set) {
            $files = ($set['paths'] ?? null) !== null
                ? array_filter($set['paths'], is_file(...))
                : glob($set['directory'] . '/' . $set['pattern']);
            if ($files === false) {
                $files = [];
            }
            $files = array_values(array_filter($files, is_file(...)));
            $files = array_map(static fn(string $file): string => str_replace(
                '\\',
                replace: '/',
                subject: $file,
            ), $files);
            sort($files, SORT_STRING);
            if (count($files) > RuntimeSchemaSnapshot::MAX_SOURCES) {
                $state->snapshot['truncated'] = true;
                $state->error('runtime-source-limit', 'Runtime source collection limit exceeded.');
                $files = array_slice($files, offset: 0, length: RuntimeSchemaSnapshot::MAX_SOURCES);
            }
            foreach ($files as $file) {
                CollectionSources::file($state, $file);
            }
            $files = array_values(array_filter(
                $files,
                static fn(string $file): bool => ($state->snapshot['sources'][$file] ?? null) !== null,
            ));
            $state->snapshot['sourceSets'][] = $set + ['files' => $files];
        }
    }

    public static function application(CollectionState $state, object $app): void
    {
        $configPath = str_replace('\\', replace: '/', subject: $app->configPath());
        if ($configPath !== $state->plan->root . '/config') {
            $state->sets[] = ['directory' => $configPath, 'pattern' => '*.php'];
        }
        $environmentPath = str_replace('\\', replace: '/', subject: $app->environmentPath());
        if ($environmentPath !== $state->plan->root) {
            $state->sets[] = ['directory' => $environmentPath, 'pattern' => '.env*'];
        }
        self::capture($state);
    }
}
