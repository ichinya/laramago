<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** One effective custom Blade directive, located at its source registration name. */
final readonly class BladeDirectiveDeclaration
{
    public function __construct(
        public string $name,
        public string $kind,
        public string $path,
        public int $start,
        public int $end,
        public bool $declaresExpressionParameter,
    ) {}
}
