<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A literal configuration key in the exact source snapshot parsed by PhpSource. */
final readonly class ConfigurationDeclaration
{
    public function __construct(
        /** Traversable dotted key, or null when the literal name contains a dot. */
        public ?string $key,
        public string $arrayKey,
        public string $name,
        public string $file,
        public int $start,
        public int $end,
        public int $line,
        public string $contentHash,
        /** False when a later dynamic entry in the same array may replace this key. */
        public bool $sourceSelected,
    ) {}
}
