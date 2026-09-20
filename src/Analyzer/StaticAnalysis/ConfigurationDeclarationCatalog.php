<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Positive literal declarations from one configuration array expression. */
final readonly class ConfigurationDeclarationCatalog
{
    /** @param list<ConfigurationDeclaration> $declarations */
    public function __construct(
        public array $declarations,
        public bool $sourceComplete,
    ) {}
}
