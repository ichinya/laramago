<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit complete effective rule names, including builtins, extensions and custom resolvers. */
final class ValidationRuleNames
{
    private bool $complete = false;
    /** @var array<string, true> */
    private array $names = [];

    public function __construct(string $root)
    {
        $json = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $json === false ? null : json_decode($json, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($options) ? $options['validation-rule-names'] ?? null : null;
        if (! is_array($catalog) || ($catalog['complete'] ?? null) !== true) {
            return;
        }
        /** @var mixed $names */
        $names = $catalog['names'] ?? null;
        if (! is_array($names) || ! array_is_list($names)) {
            return;
        }
        /** @var mixed $name */
        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)) {
                return;
            }
            $key = self::key($name);
            if ($key === null) {
                return;
            }
            $this->names[$key] = true;
        }
        $this->complete = true;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function contains(string $name): bool
    {
        $key = self::key($name);

        return $key !== null && isset($this->names[$key]);
    }

    private static function key(string $name): ?string
    {
        $name = trim(explode(':', $name, 2)[0]);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) !== 1) {
            return null;
        }

        return strtolower(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))));
    }
}
