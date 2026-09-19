<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Original-source byte range [start, end), with one-based lines and byte columns (not LSP UTF-16). */
final readonly class BladeSourceDiagnostic
{
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
        public int $start,
        public int $end,
        public int $line,
        public int $column,
        public int $endLine,
        public int $endColumn,
    ) {}
}
