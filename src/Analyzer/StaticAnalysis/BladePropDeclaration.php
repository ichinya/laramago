<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** One literal @props array entry. A default is present only for a string key. */
final readonly class BladePropDeclaration
{
    public function __construct(
        public string $name,
        public bool $hasDefault,
    ) {}
}
