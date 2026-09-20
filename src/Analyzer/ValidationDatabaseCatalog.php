<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

/** Explicit assertions about the effective presence verifier's tables and columns. */
final class ValidationDatabaseCatalog
{
    private bool $nativeRuleSemantics = false;

    /** @var array<string, array{complete: bool, tables: array<string, array{complete: bool, columns: list<string>}>}> */
    private array $connections = [];

    public function __construct(string $root)
    {
        $contents = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($contents === false) {
            return;
        }
        /** @var mixed $json */
        $json = json_decode($contents, true);
        /** @var mixed $options */
        $options = is_array($json) ? $json['extra']['laramago']['validation-database'] ?? null : null;
        $this->nativeRuleSemantics = is_array($options) && ($options['native-rule-semantics'] ?? null) === true;
        /** @var mixed $configured */
        $configured = is_array($options) ? $options['connections'] ?? null : null;
        if (! is_array($configured)) {
            return;
        }
        /** @var mixed $connection */
        foreach ($configured as $name => $connection) {
            if (
                ! is_string($name)
                || $name === ''
                || ! is_array($connection)
                || ! is_array($connection['tables'] ?? null)
            ) {
                continue;
            }
            $tables = [];
            $complete = ($connection['complete'] ?? null) === true;
            /** @var mixed $definition */
            foreach ($connection['tables'] as $table => $definition) {
                if (! is_string($table) || $table === '' || ! is_array($definition)) {
                    $complete = false;
                    continue;
                }
                /** @var mixed $columns */
                $columns = $definition['columns'] ?? null;
                if (! is_array($columns) || ! array_is_list($columns)) {
                    $complete = false;
                    continue;
                }
                $valid = true;
                $validColumns = [];
                /** @var mixed $column */
                foreach ($columns as $column) {
                    if (! is_string($column) || $column === '' || str_contains($column, "\0")) {
                        $valid = false;
                        break;
                    }
                    $validColumns[] = $column;
                }
                if (! $valid) {
                    $complete = false;
                    continue;
                }
                $tables[$table] = [
                    'complete' => ($definition['complete'] ?? null) === true,
                    'columns' => array_values(array_unique($validColumns)),
                ];
            }
            $this->connections[$name] = [
                'complete' => $complete,
                'tables' => $tables,
            ];
        }
    }

    /** Null means the assertion is insufficient to prove absence. */
    public function table(string $connection, string $table): ?bool
    {
        if (! $this->nativeRuleSemantics) {
            return null;
        }
        $scope = $this->connections[$connection] ?? null;
        if ($scope === null) {
            return null;
        }
        if (! isset($scope['tables'][$table])) {
            foreach (array_keys($scope['tables']) as $known) {
                if (strcasecmp($known, $table) === 0) {
                    // Identifier case sensitivity depends on the database platform.
                    return null;
                }
            }
        }

        return isset($scope['tables'][$table]) ? true : ($scope['complete'] ? false : null);
    }

    public function enabled(): bool
    {
        return $this->nativeRuleSemantics && $this->connections !== [];
    }

    /** Null means the table or its column set is not asserted complete. */
    public function column(string $connection, string $table, string $column): ?bool
    {
        if (! $this->nativeRuleSemantics) {
            return null;
        }
        $entry = $this->connections[$connection]['tables'][$table] ?? null;
        if ($entry === null) {
            return null;
        }
        foreach ($entry['columns'] as $known) {
            if (strcasecmp($known, $column) === 0) {
                return $known === $column ? true : null;
            }
        }

        return $entry['complete'] ? false : null;
    }
}
