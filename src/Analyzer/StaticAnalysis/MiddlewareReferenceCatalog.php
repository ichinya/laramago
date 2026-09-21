<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit permitted middleware reference strings, independent of runtime resolution. */
final class MiddlewareReferenceCatalog
{
    /** @var list<string>|null */
    private ?array $references = null;
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
        $catalog = is_array($options) ? $options['middleware-references'] ?? null : null;
        if (! is_array($catalog)) {
            return;
        }

        /** @var mixed $references */
        $references = $catalog['references'] ?? null;
        /** @var mixed $complete */
        $complete = array_key_exists('complete', $catalog) ? $catalog['complete'] : false;
        if (! is_array($references) || ! array_is_list($references) || ! is_bool($complete)) {
            return;
        }

        $valid = [];
        /** @var mixed $reference */
        foreach ($references as $reference) {
            if (! is_string($reference)) {
                return;
            }
            if (! in_array($reference, $valid, true)) {
                $valid[] = $reference;
            }
        }

        $this->references = $valid;
        $this->complete = $complete;
    }

    /** @return list<string>|null Null means no valid explicit policy. */
    public function references(): ?array
    {
        return $this->references;
    }

    public function isComplete(): bool
    {
        return $this->references !== null && $this->complete;
    }

    /** True if permitted; false only under an explicit complete policy; null otherwise. */
    public function contains(string $reference): ?bool
    {
        if ($this->references === null) {
            return null;
        }
        if (in_array($reference, $this->references, true)) {
            return true;
        }

        return $this->complete ? false : null;
    }
}
