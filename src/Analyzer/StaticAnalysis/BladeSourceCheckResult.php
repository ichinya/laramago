<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A bounded check result; an empty list never asserts complete template validity. */
final readonly class BladeSourceCheckResult
{
    /**
     * sourceScanComplete describes syntax coverage only, not catalog or runtime completeness.
     * @param list<BladeSourceDiagnostic> $diagnostics
     */
    public function __construct(
        public array $diagnostics,
        public bool $sourceScanComplete,
    ) {}
}
