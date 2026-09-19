<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Positive references only; incompleteness never proves that an omitted name is absent. */
final class BladeReferenceScan
{
    /** @param list<BladeReference> $references */
    public function __construct(
        public readonly array $references,
        public readonly bool $complete,
    ) {}
}
