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
    /** @var array<string, list<string>> */
    private array $appendableKeys = [];

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
                unset($this->appendableKeys[$key]);
                continue;
            }
            $seen[$key] = true;
            if (! is_array($catalog)) {
                continue;
            }
            /** @var mixed $fields */
            $fields = $catalog['fields'] ?? null;
            if (
                ($catalog['complete'] ?? null) === true
                && is_array($fields)
                && array_is_list($fields)
            ) {
                $valid = [];
                /** @var mixed $field */
                foreach ($fields as $field) {
                    if (! is_string($field) || $field === '' || str_contains($field, "\0")) {
                        $valid = null;
                        break;
                    }
                    $valid[] = $field;
                }
                if ($valid !== null) {
                    $this->models[$key] = array_values(array_unique($valid));
                }
            }
            /** @var mixed $serialization */
            $serialization = $catalog['serialization'] ?? null;
            /** @var mixed $keys */
            $keys = is_array($serialization) ? $serialization['keys'] ?? null : null;
            if (
                is_array($serialization)
                && ($serialization['complete'] ?? null) === true
                && is_array($keys)
                && array_is_list($keys)
            ) {
                $valid = [];
                /** @var mixed $serializationKey */
                foreach ($keys as $serializationKey) {
                    if (
                        ! is_string($serializationKey)
                        || $serializationKey === ''
                        || str_contains($serializationKey, "\0")
                    ) {
                        $valid = null;
                        break;
                    }
                    $valid[] = $serializationKey;
                }
                if ($valid !== null) {
                    $this->serializationKeys[$key] = array_values(array_unique($valid));
                }
            }
            /** @var mixed $appends */
            $appends = $catalog['appends'] ?? null;
            /** @var mixed $appendableKeys */
            $appendableKeys = is_array($appends) ? $appends['keys'] ?? null : null;
            if (
                ! is_array($appends)
                || ($appends['complete'] ?? null) !== true
                || ! is_array($appendableKeys)
                || ! array_is_list($appendableKeys)
            ) {
                continue;
            }
            $valid = [];
            /** @var mixed $appendableKey */
            foreach ($appendableKeys as $appendableKey) {
                if (! is_string($appendableKey) || $appendableKey === '' || str_contains($appendableKey, "\0")) {
                    continue 2;
                }
                $valid[] = $appendableKey;
            }
            $this->appendableKeys[$key] = array_values(array_unique($valid));
        }
    }

    public function has(string $model): bool
    {
        return array_key_exists(strtolower(ltrim($model, '\\')), $this->models);
    }

    public function hasAny(string $model): bool
    {
        $key = strtolower(ltrim($model, '\\'));

        return array_key_exists($key, $this->models) || array_key_exists($key, $this->appendableKeys);
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
     * Suggest a uniquely closest field only when an exact complete catalog is available.
     *
     * Adjacent transpositions count as one edit so common literal typos remain useful,
     * while the small length-based threshold avoids guesses for unrelated names.
     */
    public function closestField(string $model, string $field): ?string
    {
        $fields = $this->fields($model);
        if ($fields === null || $field === '' || strlen($field) > 128) {
            return null;
        }
        $limit = match (true) {
            strlen($field) <= 6 => 1,
            strlen($field) <= 12 => 2,
            default => 3,
        };
        $closest = null;
        $closestDistance = $limit + 1;
        $ambiguous = false;
        foreach ($fields as $candidate) {
            if (strlen($candidate) > 128 || abs(strlen($candidate) - strlen($field)) > $limit) {
                continue;
            }
            $distance = self::editDistance(strtolower($field), strtolower($candidate));
            if ($distance > $limit || $distance > $closestDistance) {
                continue;
            }
            if ($distance === $closestDistance) {
                $ambiguous = true;
                continue;
            }
            $closest = $candidate;
            $closestDistance = $distance;
            $ambiguous = false;
        }

        return $ambiguous ? null : $closest;
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

    /**
     * Return whether native append serialization may resolve a key through a
     * legacy/Attribute accessor or class cast under an explicit complete contract.
     */
    public function containsAppendableKey(string $model, string $name): ?bool
    {
        $keys = $this->appendableKeys[strtolower(ltrim($model, '\\'))] ?? null;

        return $keys === null ? null : in_array($name, $keys, true);
    }

    /** @return list<string>|null */
    private function fields(string $model): ?array
    {
        $key = strtolower(ltrim($model, '\\'));

        return $this->models[$key] ?? null;
    }

    /** Optimal-string-alignment distance with adjacent transpositions. */
    private static function editDistance(string $left, string $right): int
    {
        $rows = [range(0, strlen($right))];
        for ($i = 1, $leftLength = strlen($left); $i <= $leftLength; $i++) {
            $rows[$i] = [$i];
            for ($j = 1, $rightLength = strlen($right); $j <= $rightLength; $j++) {
                $cost = $left[$i - 1] === $right[$j - 1] ? 0 : 1;
                $rows[$i][$j] = min(
                    $rows[$i - 1][$j] + 1,
                    $rows[$i][$j - 1] + 1,
                    $rows[$i - 1][$j - 1] + $cost,
                );
                if (
                    $i > 1
                    && $j > 1
                    && $left[$i - 1] === $right[$j - 2]
                    && $left[$i - 2] === $right[$j - 1]
                ) {
                    $rows[$i][$j] = min($rows[$i][$j], $rows[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $rows[strlen($left)][strlen($right)];
    }
}
