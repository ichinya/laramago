<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Runtime;

/** Seal a snapshot only after initial and newly discovered input sets are rechecked. */
final class CollectionRevision
{
    private readonly array $sources;
    private readonly array $sets;

    public function __construct(CollectionState $state)
    {
        $this->sources = $state->snapshot['sources'];
        $this->sets = $state->snapshot['sourceSets'];
    }

    public function finish(CollectionState $state): array
    {
        CollectionSources::included($state);
        CollectionSourceSets::capture($state);
        foreach ($this->sources as $file => $digest) {
            if (($state->snapshot['sources'][$file] ?? null) !== $digest) {
                $state->error('runtime-source-changed', 'Runtime inputs changed during collection.');
                break;
            }
        }
        if (array_slice($state->snapshot['sourceSets'], 0, count($this->sets)) !== $this->sets) {
            $state->error('runtime-source-changed', 'Runtime inputs changed during collection.');
        }
        ksort($state->snapshot['sources'], SORT_STRING);
        ksort($state->snapshot['models'], SORT_STRING);
        if (!RuntimeSchemaSnapshot::currentInputs($state->snapshot)) {
            $state->error('runtime-source-changed', 'Runtime inputs changed during collection.');
        }

        return $state->snapshot;
    }
}
