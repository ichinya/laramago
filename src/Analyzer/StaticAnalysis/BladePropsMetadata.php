<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A source-only snapshot; it makes no claim that a tag resolves to this file. */
final readonly class BladePropsMetadata
{
    /** @param list<BladePropDeclaration> $declarations */
    public function __construct(
        public bool $hasDirective,
        public array $declarations,
        public ?int $offset,
    ) {}
}
