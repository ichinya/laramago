<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A Blade file and possible local names, without a runtime registration claim. */
final readonly class BladeAnonymousComponent
{
    /** @param non-empty-list<string> $candidateNames */
    public function __construct(
        public string $path,
        public string $rootPath,
        public ?string $prefix,
        public string $relativeName,
        public array $candidateNames,
    ) {}
}
