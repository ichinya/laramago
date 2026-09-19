<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** One literal reference with a half-open byte span in the original Blade source. */
final class BladeReference
{
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly string $origin,
        public readonly string $requirement,
        public readonly int $start,
        public readonly int $end,
    ) {}
}
