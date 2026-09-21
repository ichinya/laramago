<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

/** Explicit effective column sources for exact eager-loaded owner relations. */
final class RelatedProjectionSourceCatalog
{
    /** @var array<string, array{related: string, columns: list<string>}> */
    private array $relations = [];

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
        $contracts = is_array($laramago) ? $laramago['relation-query-sources'] ?? null : null;
        if (! is_array($contracts)) {
            return;
        }
        $seen = [];
        /** @var mixed $contract */
        foreach ($contracts as $relation => $contract) {
            $match = [];
            if (
                ! is_string($relation)
                || preg_match(
                    '/^\\\\?([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::([A-Za-z_][A-Za-z0-9_]*)$/D',
                    $relation,
                    $match,
                ) !== 1
            ) {
                continue;
            }
            $key = strtolower(ltrim($match[1], '\\').'::'.$match[2]);
            if (isset($seen[$key])) {
                unset($this->relations[$key]);
                continue;
            }
            $seen[$key] = true;
            /** @var mixed $related */
            $related = is_array($contract) ? $contract['related-model'] ?? null : null;
            /** @var mixed $columns */
            $columns = is_array($contract) ? $contract['columns'] ?? null : null;
            if (
                ! is_array($contract)
                || ($contract['complete'] ?? null) !== true
                || ($contract['native-column-semantics'] ?? null) !== true
                || ! is_string($related)
                || preg_match(
                    '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D',
                    $related,
                ) !== 1
                || ! is_array($columns)
                || ! array_is_list($columns)
            ) {
                continue;
            }
            $valid = [];
            /** @var mixed $column */
            foreach ($columns as $column) {
                if (! is_string($column) || $column === '' || str_contains($column, "\0")) {
                    continue 2;
                }
                $valid[] = $column;
            }
            $this->relations[$key] = [
                'related' => ltrim($related, '\\'),
                'columns' => array_values(array_unique($valid)),
            ];
        }
    }

    public function hasOwner(string $owner): bool
    {
        $prefix = strtolower(ltrim($owner, '\\')).'::';
        foreach ($this->relations as $key => $_contract) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function contains(string $owner, string $relation, string $related, string $column): ?bool
    {
        $contract = $this->relations[strtolower(ltrim($owner, '\\').'::'.$relation)] ?? null;
        if ($contract === null || strcasecmp($contract['related'], ltrim($related, '\\')) !== 0) {
            return null;
        }

        return in_array($column, $contract['columns'], true);
    }
}
