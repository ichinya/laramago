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

    /** A complete absence describes only this selected source array, not runtime configuration. */
    public function confidence(string $key): MetadataConfidence
    {
        if (in_array($key, $this->keys, true)) {
            return MetadataConfidence::KnownPositive;
        }

        return $this->sourceComplete ? MetadataConfidence::CompleteAbsent : MetadataConfidence::Unknown;
    }
}
