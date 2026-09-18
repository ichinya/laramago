<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

/** Explicit, exact-model field catalogs; no fields are inferred from schema alone. */
final class ModelFieldCatalog
{
    /** @var array<string, list<string>> */
    private array $models = [];
    /** @var array<string, list<string>> */
    private array $serializationKeys = [];

    public function __construct(string $projectRoot)
    {
        $path = rtrim($projectRoot, '/\\').'/composer.json';
        $text = @file_get_contents($path);
        if ($text === false) {
            return;
        }
        /** @var mixed $configuration */
        $configuration = json_decode($text, true);
        if (! is_array($configuration)) {
            return;
        }
        /** @var mixed $extra */
        $extra = $configuration['extra'] ?? null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalogs */
        $catalogs = is_array($options) ? $options['model-fields'] ?? null : null;
        if (! is_array($catalogs)) {
            return;
        }
        $seen = [];
        /** @var mixed $catalog */
        foreach ($catalogs as $model => $catalog) {
            if (! is_string($model) || $model === '') {
                continue;
            }
            $key = strtolower(ltrim($model, '\\'));
            if (isset($seen[$key])) {
                unset($this->models[$key]);
                unset($this->serializationKeys[$key]);
                continue;
            }
            $seen[$key] = true;
            /** @var mixed $fields */
            $fields = is_array($catalog) ? $catalog['fields'] ?? null : null;
            if (
                ! is_array($catalog)
                || ($catalog['complete'] ?? null) !== true
                || ! is_array($fields)
                || ! array_is_list($fields)
            ) {
                continue;
            }
            $valid = [];
            /** @var mixed $field */
            foreach ($fields as $field) {
                if (! is_string($field) || $field === '' || str_contains($field, "\0")) {
                    continue 2;
                }
                $valid[] = $field;
            }
            $this->models[$key] = array_values(array_unique($valid));
            /** @var mixed $serialization */
            $serialization = $catalog['serialization'] ?? null;
            /** @var mixed $keys */
            $keys = is_array($serialization) ? $serialization['keys'] ?? null : null;
            if (
                ! is_array($serialization)
                || ($serialization['complete'] ?? null) !== true
                || ! is_array($keys)
                || ! array_is_list($keys)
            ) {
                continue;
            }
            $valid = [];
            /** @var mixed $serializationKey */
            foreach ($keys as $serializationKey) {
                if (
                    ! is_string($serializationKey)
                    || $serializationKey === ''
                    || str_contains($serializationKey, "\0")
                ) {
                    continue 2;
                }
                $valid[] = $serializationKey;
            }
            $this->serializationKeys[$key] = array_values(array_unique($valid));
        }
    }

    public function has(string $model): bool
    {
        return array_key_exists(strtolower(ltrim($model, '\\')), $this->models);
    }

    /**
     * Return true for a declared field, false for an absent field in an
     * explicitly complete exact-model catalog, and null without such proof.
     */
    public function contains(string $model, string $field): ?bool
    {
        $fields = $this->fields($model);
        if ($fields === null) {
            return null;
        }

        return in_array($field, $fields, true);
    }

    /** Guarded attribute matching follows Laravel's case-insensitive comparison. */
    public function containsCaseInsensitive(string $model, string $field): ?bool
    {
        $fields = $this->fields($model);
        if ($fields === null) {
            return null;
        }
        foreach ($fields as $candidate) {
            if (strcasecmp($candidate, $field) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hidden names may identify attributes or the pre-serialization names of
     * relationships and appends. Absence is proven only when both sets are complete.
     */
    public function containsSerializationKey(string $model, string $name): ?bool
    {
        $key = strtolower(ltrim($model, '\\'));
        if (! array_key_exists($key, $this->models) || ! array_key_exists($key, $this->serializationKeys)) {
            return null;
        }

        return in_array($name, $this->models[$key], true) || in_array($name, $this->serializationKeys[$key], true);
    }

    /** @return list<string>|null */
    private function fields(string $model): ?array
    {
        $key = strtolower(ltrim($model, '\\'));

        return $this->models[$key] ?? null;
    }
}
