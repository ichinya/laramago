<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A source-backed tag candidate, not a rendered component or inferred prop contract. */
final readonly class BladeComponentTagTarget
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $path,
        public int $precedence,
    ) {}
}
