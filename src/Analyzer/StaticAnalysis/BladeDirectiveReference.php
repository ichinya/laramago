<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Original Blade-source byte range [start, end), including the leading @. */
final readonly class BladeDirectiveReference
{
    public function __construct(
        public string $name,
        public int $start,
        public int $end,
        /** known, missing, or unknown when the effective registry is incomplete. */
        public string $status,
    ) {}
}
