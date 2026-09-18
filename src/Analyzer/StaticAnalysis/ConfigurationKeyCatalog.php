<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Literal string keys proven from one configuration array expression. */
final readonly class ConfigurationKeyCatalog
{
    /** @param list<string> $keys */
    public function __construct(
        public array $keys,
        public bool $sourceComplete,
    ) {}
}
