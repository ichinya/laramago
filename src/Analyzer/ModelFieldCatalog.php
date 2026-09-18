<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

/** Explicit, exact-model field catalogs; no fields are inferred from schema alone. */
final class ModelFieldCatalog
{
    /** @var array<string, list<string>> */
    private array $models = [];

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
        }
    }

    /**
     * Return true for a declared field, false for an absent field in an
     * explicitly complete exact-model catalog, and null without such proof.
     */
    public function contains(string $model, string $field): ?bool
    {
        $key = strtolower(ltrim($model, '\\'));
        if (! array_key_exists($key, $this->models)) {
            return null;
        }

        return in_array($field, $this->models[$key], true);
    }
}
