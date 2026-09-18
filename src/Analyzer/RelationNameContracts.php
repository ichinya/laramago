<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

/** Explicit, exact-class completeness assertions; never discovers runtime registrations. */
final class RelationNameContracts
{
    /** @var array<string, list<string>> */
    private array $models = [];

    public function __construct(string $projectRoot)
    {
        $path = rtrim($projectRoot, '/\\').'/composer.json';
        if (! is_file($path)) {
            return;
        }
        $text = @file_get_contents($path);
        if ($text === false) {
            return;
        }
        /**
         * @var mixed $configuration
         */
        $configuration = json_decode($text, true);
        if (! is_array($configuration)) {
            return;
        }
        /**
         * @var mixed $extra
         */
        $extra = $configuration['extra'] ?? null;
        /**
         * @var mixed $options
         */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /**
         * @var mixed $contracts
         */
        $contracts = is_array($options) ? $options['relation-names'] ?? null : null;
        if (! is_array($contracts)) {
            return;
        }
        $seen = [];
        /**
         * @var mixed $contract
         */
        foreach ($contracts as $model => $contract) {
            if (! is_string($model)) {
                continue;
            }
            $model = strtolower(ltrim($model, '\\'));
            if (isset($seen[$model])) {
                unset($this->models[$model]);
                continue;
            }
            $seen[$model] = true;
            if (! is_array($contract) || ($contract['complete'] ?? null) !== true) {
                continue;
            }
            /**
             * @var mixed $dynamic
             */
            $dynamic = array_key_exists('dynamic', $contract) ? $contract['dynamic'] : [];
            if (! is_array($dynamic) || ! array_is_list($dynamic)) {
                continue;
            }
            $names = [];
            /**
             * @var mixed $name
             */
            foreach ($dynamic as $name) {
                if (! is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
                    continue 2;
                }
                $names[] = $name;
            }
            $this->models[$model] = $names;
        }
    }

    public function isComplete(string $model): bool
    {
        return array_key_exists(strtolower(ltrim($model, '\\')), $this->models);
    }

    public function isDynamic(string $model, string $name): bool
    {
        return in_array($name, $this->models[strtolower(ltrim($model, '\\'))] ?? [], true);
    }
}
