<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

final class Column
{
    public function __construct(
        public readonly string $type,
        public readonly bool $nullable = false,
    ) {}
}
