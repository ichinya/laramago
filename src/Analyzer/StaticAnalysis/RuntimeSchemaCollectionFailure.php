<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Carries only fixed collector failure categories, never application messages. */
final class RuntimeSchemaCollectionFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $diagnostic,
    ) {
        parent::__construct('Runtime metadata collector failed.');
    }
}
