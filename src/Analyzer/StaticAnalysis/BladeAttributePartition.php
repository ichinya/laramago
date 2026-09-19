<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Names-only snapshot of component data and the remaining attribute bag. */
final readonly class BladeAttributePartition
{
    /**
     * @param array<string, string> $propAttributes Parsed attribute name => declared prop/parameter name.
     * @param list<string> $bagAttributes Parsed attribute names retained in the bag.
     */
    public function __construct(
        public array $propAttributes,
        public array $bagAttributes,
    ) {}
}
