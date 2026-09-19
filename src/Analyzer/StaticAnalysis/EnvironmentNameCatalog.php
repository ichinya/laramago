<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit environment names, without reading environment files or values. */
final class EnvironmentNameCatalog
{
    /** @var list<string>|null */
    private ?array $names = null;
    private bool $complete = false;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return;
        }

        /** @var mixed $composer */
        $composer = json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($options) ? $options['environment-names'] ?? null : null;
        if (! is_array($catalog)) {
            return;
        }

        /** @var mixed $names */
        $names = $catalog['names'] ?? null;
        /** @var mixed $complete */
        $complete = array_key_exists('complete', $catalog) ? $catalog['complete'] : false;
        if (! is_array($names) || ! array_is_list($names) || ! is_bool($complete)) {
            return;
        }

        $valid = [];
        /** @var mixed $name */
        foreach ($names as $name) {
            if (! is_string($name) || $name === '' || preg_match('/[\x00-\x20\x7F=]/', $name) === 1) {
                return;
            }
            if (! in_array($name, $valid, true)) {
                $valid[] = $name;
            }
        }

        $this->names = $valid;
        $this->complete = $complete;
    }

    /** @return list<string>|null Null means no valid explicit catalog. */
    public function names(): ?array
    {
        return $this->names;
    }

    public function isComplete(): bool
    {
        return $this->names !== null && $this->complete;
    }

    /** True if cataloged; false only under an explicit complete catalog; null otherwise. */
    public function contains(string $name): ?bool
    {
        if ($this->names === null) {
            return null;
        }
        if (in_array($name, $this->names, true)) {
            return true;
        }

        return $this->complete ? false : null;
    }
}
