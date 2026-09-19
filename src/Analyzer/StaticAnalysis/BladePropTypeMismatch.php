<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A source-backed literal incompatibility, not an analyzer diagnostic. */
final readonly class BladePropTypeMismatch
{
    public function __construct(
        public string $parameter,
        public string $expected,
        public string $actual,
    ) {}
}
