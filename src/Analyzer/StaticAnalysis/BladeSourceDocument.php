<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use InvalidArgumentException;

/** One original Blade buffer, including its caller-supplied file or editor-buffer identity. */
final readonly class BladeSourceDocument
{
    /** @var non-empty-list<int> */
    private array $lineStarts;

    public function __construct(
        public string $path,
        public string $source,
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('A Blade source identity cannot be empty.');
        }
        $starts = [0];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if ($source[$i] === "\r") {
                if (($i + 1) < $length && $source[$i + 1] === "\n") {
                    $i++;
                }
                $starts[] = $i + 1;
            } elseif ($source[$i] === "\n") {
                $starts[] = $i + 1;
            }
        }
        $this->lineStarts = $starts;
    }

    public function diagnostic(string $code, string $message, int $start, int $end): BladeSourceDiagnostic
    {
        if ($start < 0 || $end < $start || $end > strlen($this->source)) {
            throw new InvalidArgumentException('A diagnostic must address the original Blade buffer.');
        }
        [$line, $column] = $this->position($start);
        [$endLine, $endColumn] = $this->position($end);

        return new BladeSourceDiagnostic(
            $this->path,
            $code,
            $message,
            $start,
            $end,
            $line,
            $column,
            $endLine,
            $endColumn,
        );
    }

    /** @return array{int, int} One-based line and byte column; CRLF is one line break. */
    private function position(int $offset): array
    {
        $low = 0;
        $high = count($this->lineStarts) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($this->lineStarts[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return [$low + 1, $offset - $this->lineStarts[$low] + 1];
    }
}
