<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

/** Explicit effective column references for fresh standard queries of exact models. */
final class QuerySourceCatalog
{
    /** @var array<string, list<string>> */
    private array $models = [];

    public function __construct(string $projectRoot)
    {
        $text = @file_get_contents(rtrim($projectRoot, '/\\').'/composer.json');
        if ($text === false) {
            return;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $laramago */
        $laramago = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $contracts */
        $contracts = is_array($laramago) ? $laramago['query-sources'] ?? null : null;
        if (! is_array($contracts)) {
            return;
        }
        $seen = [];
        /** @var mixed $contract */
        foreach ($contracts as $model => $contract) {
            if (! is_string($model) || $model === '') {
                continue;
            }
            $key = strtolower(ltrim($model, '\\'));
            if (isset($seen[$key])) {
                unset($this->models[$key]);
                continue;
            }
            $seen[$key] = true;
            /** @var mixed $columns */
            $columns = is_array($contract) ? $contract['columns'] ?? null : null;
            if (
                ! is_array($contract)
                || ($contract['complete'] ?? null) !== true
                || ($contract['native-column-semantics'] ?? null) !== true
                || ! is_array($columns)
                || ! array_is_list($columns)
            ) {
                continue;
            }
            $valid = [];
            for ($index = 0, $count = count($columns); $index < $count; $index++) {
                if (
                    ! is_string($columns[$index])
                    || $columns[$index] === ''
                    || str_contains($columns[$index], "\0")
                ) {
                    continue 2;
                }
                $valid[] = $columns[$index];
            }
            $this->models[$key] = array_values(array_unique($valid));
        }
    }

    public function has(string $model): bool
    {
        return array_key_exists(strtolower(ltrim($model, '\\')), $this->models);
    }

    public function contains(string $model, string $column): ?bool
    {
        $columns = $this->models[strtolower(ltrim($model, '\\'))] ?? null;

        return $columns === null ? null : in_array($column, $columns, true);
    }
}
