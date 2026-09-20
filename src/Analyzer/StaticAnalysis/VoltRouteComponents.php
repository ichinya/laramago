<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Application assertion about every effective component name accepted by Volt routes. */
final class VoltRouteComponents
{
    /** @var array<array-key, true>|null */
    private ?array $names = null;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalogs */
        $catalogs = is_array($settings) ? $settings['reference-catalogs'] ?? null : null;
        /** @var mixed $configuration */
        $configuration = is_array($catalogs) ? $catalogs['volt-route-components'] ?? null : null;
        /** @var mixed $names */
        $names = is_array($configuration) ? $configuration['names'] ?? null : null;
        if (
            ! is_array($configuration)
            || ($configuration['complete'] ?? null) !== true
            || ! is_array($names)
            || ! array_is_list($names)
        ) {
            return;
        }

        $result = [];
        /** @var mixed $name */
        foreach ($names as $name) {
            if (! is_string($name) || $name === '' || isset($result[$name])) {
                return;
            }
            $result[$name] = true;
        }
        $this->names = $result;
    }

    public function contains(string $name): ?bool
    {
        return $this->names === null ? null : isset($this->names[$name]);
    }

    public function isComplete(): bool
    {
        return $this->names !== null;
    }
}
