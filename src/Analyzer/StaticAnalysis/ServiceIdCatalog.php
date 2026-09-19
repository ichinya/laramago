<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit permitted service IDs, independent of runtime container resolvability. */
final class ServiceIdCatalog
{
    /** @var list<string>|null */
    private ?array $ids = null;
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
        $catalog = is_array($options) ? $options['service-ids'] ?? null : null;
        if (! is_array($catalog)) {
            return;
        }

        /** @var mixed $ids */
        $ids = $catalog['ids'] ?? null;
        /** @var mixed $complete */
        $complete = array_key_exists('complete', $catalog) ? $catalog['complete'] : false;
        if (! is_array($ids) || ! array_is_list($ids) || ! is_bool($complete)) {
            return;
        }

        $valid = [];
        /** @var mixed $name */
        foreach ($ids as $name) {
            if (! is_string($name)) {
                return;
            }
            if (! in_array($name, $valid, true)) {
                $valid[] = $name;
            }
        }

        $this->ids = $valid;
        $this->complete = $complete;
    }

    /** @return list<string>|null Null means no valid explicit catalog. */
    public function ids(): ?array
    {
        return $this->ids;
    }

    public function isComplete(): bool
    {
        return $this->ids !== null && $this->complete;
    }

    /** True if cataloged; false only under an explicit complete catalog; null otherwise. */
    public function contains(string $name): ?bool
    {
        if ($this->ids === null) {
            return null;
        }
        if (in_array($name, $this->ids, true)) {
            return true;
        }

        return $this->complete ? false : null;
    }
}
