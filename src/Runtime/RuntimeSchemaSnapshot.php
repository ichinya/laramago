<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

use RuntimeException;

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
        if (!is_int($size) || $size > self::MAX_BYTES) {
            throw new RuntimeException('Invalid runtime schema snapshot.');
        }
        $contents = RuntimeFiles::read($path, self::MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new RuntimeException('Invalid runtime schema snapshot.');
        }

        return self::decode($contents, $root);
    }

    public static function decode(string $contents, string $root): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('Invalid runtime schema snapshot.');
        }
        try {
            $snapshot = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid runtime schema snapshot.');
        }
        if (!is_array($snapshot)) {
            throw new RuntimeException('Invalid runtime schema snapshot.');
        }

        return self::validate($snapshot, $root);
    }

    public static function validate(array $snapshot, string $root): array
    {
        $canonical = self::canonical($root);
        if (
            ($snapshot['schemaVersion'] ?? null) !== 1
            || $canonical === null
            || ($snapshot['projectRoot'] ?? null) !== $canonical
            || ($snapshot['scope'] ?? null) !== ['kind' => 'runtime-schema', 'evidence' => 'evaluated-schema']
            || !is_bool($snapshot['truncated'] ?? null)
        ) {
            self::invalid();
        }
        $models = $snapshot['models'] ?? null;
        $plan = $snapshot['plan']['models'] ?? null;
        $sources = $snapshot['sources'] ?? null;
        $sets = $snapshot['sourceSets'] ?? null;
        $errors = $snapshot['errors'] ?? null;
        if (
            !is_array($models)
            || count($models) > self::MAX_MODELS
            || !is_array($plan)
            || !array_is_list($plan)
            || count($plan) > self::MAX_MODELS
            || !is_array($sources)
            || count($sources) > self::MAX_SOURCES
            || !is_array($sets)
            || !array_is_list($sets)
            || count($sets) > 32
            || !is_array($errors)
            || !array_is_list($errors)
            || count($errors) > 256
        ) {
            self::invalid();
        }
        foreach ($plan as $model) {
            if (!self::className($model)) {
                self::invalid();
            }
        }
        if (count(array_unique($plan)) !== count($plan)) {
            self::invalid();
        }
        if (isset($snapshot['plan']['autoload']) && !self::absolute($snapshot['plan']['autoload'])) {
            self::invalid();
        }
        $configSet = false;
        foreach ($models as $name => $model) {
            if (
                !self::className($name)
                || !in_array($name, $plan, true)
                || !is_array($model)
                || !self::name($model['connection'] ?? null)
                || !self::name($model['table'] ?? null)
                || !is_string($model['databaseIdentity'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $model['databaseIdentity']) !== 1
                || !is_array($model['casts'] ?? null)
                || count($model['casts']) > self::MAX_COLUMNS
                || !is_array($model['columns'] ?? null)
                || count($model['columns']) > self::MAX_COLUMNS
            ) {
                self::invalid();
            }
            foreach ($model['casts'] as $attribute => $cast) {
                if (!self::name($attribute) || !self::name($cast, 1024)) {
                    self::invalid();
                }
            }
            foreach ($model['columns'] as $column => $definition) {
                if (
                    !self::name($column)
                    || !is_array($definition)
                    || !is_string($definition['typeName'] ?? null)
                    || strlen($definition['typeName']) > 256
                    || !is_bool($definition['nullable'] ?? null)
                    || !is_bool($definition['strict'] ?? null)
                ) {
                    self::invalid();
                }
            }
        }
        foreach ($sources as $path => $digest) {
            if (!self::absolute($path) || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                self::invalid();
            }
        }
        foreach ($sets as $set) {
            if (
                !is_array($set)
                || !is_array($set['files'] ?? null)
                || !array_is_list($set['files'])
                || count($set['files']) > self::MAX_SOURCES
            ) {
                self::invalid();
            }
            if (isset($set['paths'])) {
                if (
                    !is_array($set['paths'])
                    || !array_is_list($set['paths'])
                    || count($set['paths']) > self::MAX_SOURCES
                ) {
                    self::invalid();
                }
                foreach ($set['paths'] as $path) {
                    if (!self::absolute($path)) {
                        self::invalid();
                    }
                }
            } elseif (
                !self::absolute($set['directory'] ?? null)
                || !in_array($set['pattern'] ?? null, ['*.php', '.env*'], true)
            ) {
                self::invalid();
            }
            if (($set['directory'] ?? null) === $canonical . '/config' && ($set['pattern'] ?? null) === '*.php') {
                $configSet = true;
            }
            foreach ($set['files'] as $path) {
                if (!self::absolute($path) || !isset($sources[$path])) {
                    self::invalid();
                }
            }
        }
        if (!$configSet) {
            self::invalid();
        }
        foreach ($errors as $error) {
            if (
                !is_array($error)
                || !self::name($error['code'] ?? null)
                || !array_key_exists('file', $error)
                || $error['file'] !== null && !self::absolute($error['file'])
                || !self::name($error['message'] ?? null, 1024)
            ) {
                self::invalid();
            }
        }
        if ($errors === [] && !$snapshot['truncated'] && count($models) !== count($plan)) {
            self::invalid();
        }

        return $snapshot;
    }

    public static function currentInputs(array $snapshot): bool
    {
        foreach ($snapshot['sources'] ?? [] as $path => $digest) {
            clearstatcache(true, $path);
            if (!is_file($path) || RuntimeFiles::digest($path) !== $digest) {
                return false;
            }
        }
        foreach ($snapshot['sourceSets'] ?? [] as $set) {
            $files = isset($set['paths'])
                ? array_values(array_filter($set['paths'], is_file(...)))
                : (glob($set['directory'] . '/' . $set['pattern']) ?: []);
            $files = array_values(array_filter($files, is_file(...)));
            $files = array_map(static fn(string $file): string => str_replace('\\', '/', $file), $files);
            sort($files, SORT_STRING);
            if ($files !== $set['files']) {
                return false;
            }
        }

        return true;
    }

    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(self::sorted($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function canonical(string $path): ?string
    {
        $resolved = realpath($path);

        return $resolved === false ? null : rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }

    private static function className(mixed $value): bool
    {
        return (
            is_string($value)
            && strlen($value) <= 256
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $value) === 1
        );
    }

    private static function name(mixed $value, int $limit = 256): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit && !str_contains($value, "\0");
    }

    private static function absolute(mixed $value): bool
    {
        return (
            self::name($value, 4096)
            && !str_contains($value, '\\')
            && preg_match('~\A(?:[A-Za-z]:/|/)[^\x00]*\z~', $value) === 1
            && !preg_match('~(?:^|/)\.\.?(/|$)~', $value)
        );
    }

    private static function invalid(): never
    {
        throw new RuntimeException('Invalid runtime schema snapshot.');
    }
}
