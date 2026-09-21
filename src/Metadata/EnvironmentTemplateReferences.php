<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

/**
 * Source-only `${NAME}` references from explicitly selected dotenv templates.
 *
 * This exporter never loads dotenv, resolves a value, or reads an actual `.env`.
 * Missing declarations are deliberately not errors: phpdotenv may resolve names
 * from the repository into which a template is loaded.
 */
final class EnvironmentTemplateReferences
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_DECLARATIONS = 20000;
    private const MAX_REFERENCES = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative `.env.example` or `.env.template` files.
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        clearstatcache(true);
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException('Project root must be an existing directory.');
        }
        if (! array_is_list($files)) {
            throw new \InvalidArgumentException(
                'Environment template sources must be a list of project-relative files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $declarations = [];
        $references = [];
        $errors = [];
        $uncertainties = [];
        $reasons = [];
        $totalBytes = 0;
        $seen = [];

        if (count($files) > self::MAX_FILES) {
            $reasons['file-limit'] = true;
        }

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validRelativePath($file)) {
                $errors[] = self::error(null, 'invalid-source');
                continue;
            }

            clearstatcache(true);
            $path = realpath($prefix.$file);
            if ($path === false || ! is_file($path)) {
                $errors[] = self::error($file, 'unreadable-source');
                continue;
            }

            $path = str_replace('\\', '/', $path);
            $contained = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($path), strtolower($prefix))
                : str_starts_with($path, $prefix);
            if (! $contained || ! in_array(basename($path), ['.env.example', '.env.template'], true)) {
                $errors[] = self::error($file, 'unsupported-source');
                continue;
            }

            $identity = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
            if ($remaining <= 0) {
                $reasons['total-byte-limit'] = true;
                break;
            }
            $contents = @file_get_contents($path, false, null, 0, min(self::MAX_FILE_BYTES, $remaining) + 1);
            if ($contents === false) {
                $errors[] = self::error($file, 'unreadable-source');
                continue;
            }

            $bytes = strlen($contents);
            $totalBytes += $bytes;
            if ($bytes > self::MAX_FILE_BYTES) {
                $reasons['file-byte-limit'] = true;
                $errors[] = self::error($file, 'source-limit');
                continue;
            }
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error($file, 'source-limit');
                break;
            }

            $hash = hash('sha256', $contents);
            $scan = self::scan($contents);
            foreach ($scan['errors'] as $entry) {
                $errors[] = self::sourceNotice($path, $entry['line'], $entry['code'], false);
            }
            foreach ($scan['uncertainties'] as $entry) {
                $uncertainties[] = self::sourceNotice($path, $entry['line'], $entry['code'], true);
            }
            foreach ($scan['declarations'] as $declaration) {
                if (count($declarations) >= self::MAX_DECLARATIONS) {
                    $reasons['declaration-limit'] = true;
                    break 2;
                }
                $declarations[] = self::location($declaration, $path, $hash);
            }
            foreach ($scan['references'] as $reference) {
                if (count($references) >= self::MAX_REFERENCES) {
                    $reasons['reference-limit'] = true;
                    break 2;
                }
                $references[] = self::location($reference, $path, $hash);
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => ['kind' => 'environment-references', 'evidence' => 'source-only'],
            'declarations' => $declarations,
            'references' => $references,
            'errors' => $errors,
            'uncertainties' => $uncertainties,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function validRelativePath(string $file): bool
    {
        if (
            $file === ''
            || str_contains($file, "\0")
            || str_contains($file, ':')
            || preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file) === 1
        ) {
            return false;
        }

        $requested = basename(str_replace('\\', '/', $file));

        return in_array($requested, ['.env.example', '.env.template'], true);
    }

    /**
     * @return array{
     *   declarations: list<array{name: string, start: int, end: int, line: int}>,
     *   references: list<array{name: string, start: int, end: int, line: int}>,
     *   errors: list<array{line: int, code: string}>,
     *   uncertainties: list<array{line: int, code: string}>
     * }
     */
    private static function scan(string $source): array
    {
        $declarations = [];
        $references = [];
        $errors = [];
        $uncertainties = [];
        $uncertaintyKeys = [];
        $lineStarts = self::lineStarts($source);
        $length = strlen($source);
        $cursor = 0;

        while ($cursor < $length) {
            [$lineEnd, $nextLine] = self::physicalLine($source, $cursor);
            $line = substr($source, $cursor, $lineEnd - $cursor);
            $trimmed = ltrim($line, " \t");
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $cursor = $nextLine;
                continue;
            }

            $matches = [];
            $matched = preg_match(
                '/\A[ \t]*(?:export[ \t]+)?(?:(?:([\'\"])([A-Za-z0-9_.]+)\1)|([A-Za-z0-9_.]+))[ \t]*=[ \t]*/',
                $line,
                $matches,
            );
            if ($matched !== 1) {
                $errors[] = ['line' => self::lineNumber($lineStarts, $cursor), 'code' => 'unsupported-entry'];
                $cursor = $nextLine;
                continue;
            }

            $whole = $matches[0];
            $name = ($matches[2] ?? '') !== '' ? $matches[2] : $matches[3] ?? '';
            $nameOffset = strrpos($whole, $name);
            if ($name === '' || $nameOffset === false) {
                $errors[] = ['line' => self::lineNumber($lineStarts, $cursor), 'code' => 'unsupported-entry'];
                $cursor = $nextLine;
                continue;
            }
            $nameStart = $cursor + $nameOffset;
            $valueStart = $cursor + strlen($whole);
            $parsed = self::parseValue($source, $valueStart, $lineEnd, $nextLine, $lineStarts);
            if (! $parsed['valid']) {
                $errors[] = ['line' => self::lineNumber($lineStarts, $cursor), 'code' => 'unsupported-entry'];
                $cursor = $parsed['next'];
                continue;
            }

            $declarations[] = [
                'name' => $name,
                'start' => $nameStart,
                'end' => $nameStart + strlen($name),
                'line' => self::lineNumber($lineStarts, $nameStart),
            ];
            array_push($references, ...$parsed['references']);
            foreach ($parsed['uncertainties'] as $uncertainty) {
                $key = $uncertainty['line'].':'.$uncertainty['code'];
                if (! isset($uncertaintyKeys[$key])) {
                    $uncertaintyKeys[$key] = true;
                    $uncertainties[] = $uncertainty;
                }
            }
            $cursor = $parsed['next'];
        }

        return compact('declarations', 'references', 'errors', 'uncertainties');
    }

    /**
     * @param list<int> $lineStarts
     * @return array{valid: bool, next: int, references: list<array{name: string, start: int, end: int, line: int}>, uncertainties: list<array{line: int, code: string}>}
     */
    private static function parseValue(
        string $source,
        int $start,
        int $lineEnd,
        int $nextLine,
        array $lineStarts,
    ): array {
        $references = [];
        $uncertainties = [];
        if ($start >= $lineEnd || $source[$start] === '#') {
            return ['valid' => true, 'next' => $nextLine, 'references' => [], 'uncertainties' => []];
        }

        if ($source[$start] === '\'') {
            $close = strpos($source, '\'', $start + 1);
            if ($close === false || $close >= $lineEnd || ! self::validTail($source, $close + 1, $lineEnd)) {
                return ['valid' => false, 'next' => $nextLine, 'references' => [], 'uncertainties' => []];
            }

            return ['valid' => true, 'next' => $nextLine, 'references' => [], 'uncertainties' => []];
        }

        if ($source[$start] === '"') {
            $length = strlen($source);
            for ($offset = $start + 1; $offset < $length; $offset++) {
                $character = $source[$offset];
                if ($character === '"') {
                    [$closingLineEnd, $closingNextLine] = self::physicalLine($source, $offset);
                    $valid = self::validTail($source, $offset + 1, $closingLineEnd);

                    return [
                        'valid' => $valid,
                        'next' => $closingNextLine,
                        'references' => $valid ? $references : [],
                        'uncertainties' => $valid ? $uncertainties : [],
                    ];
                }
                if ($character === '\\') {
                    $offset++;
                    if (
                        $offset >= $length
                        || ! in_array($source[$offset], ['"', '\\', '$', 'f', 'n', 'r', 't', 'v'], true)
                    ) {
                        return [
                            'valid' => false,
                            'next' => self::nextPhysicalLine($source, min($offset, $length)),
                            'references' => [],
                            'uncertainties' => [],
                        ];
                    }
                    continue;
                }
                if ($character === '$') {
                    self::collectReference($source, $offset, $lineStarts, $references, $uncertainties);
                }
            }

            return ['valid' => false, 'next' => $length, 'references' => [], 'uncertainties' => []];
        }

        for ($offset = $start; $offset < $lineEnd; $offset++) {
            $character = $source[$offset];
            if ($character === '#') {
                break;
            }
            if ($character === ' ' || $character === "\t") {
                if (! self::validTail($source, $offset, $lineEnd)) {
                    return ['valid' => false, 'next' => $nextLine, 'references' => [], 'uncertainties' => []];
                }
                break;
            }
            if ($character === '$') {
                self::collectReference($source, $offset, $lineStarts, $references, $uncertainties);
            }
        }

        return [
            'valid' => true,
            'next' => $nextLine,
            'references' => $references,
            'uncertainties' => $uncertainties,
        ];
    }

    /**
     * @param list<int> $lineStarts
     * @param list<array{name: string, start: int, end: int, line: int}> $references
     * @param list<array{line: int, code: string}> $uncertainties
     */
    private static function collectReference(
        string $source,
        int $offset,
        array $lineStarts,
        array &$references,
        array &$uncertainties,
    ): void {
        if (substr($source, $offset, 2) !== '${') {
            return;
        }
        $matches = [];
        $matched = preg_match('/\A\$\{([A-Za-z0-9_.]+)\}/', substr($source, $offset), $matches);
        if ($matched === 1 && isset($matches[1])) {
            $start = $offset + 2;
            $name = $matches[1];
            $references[] = [
                'name' => $name,
                'start' => $start,
                'end' => $start + strlen($name),
                'line' => self::lineNumber($lineStarts, $start),
            ];

            return;
        }
        $uncertainties[] = [
            'line' => self::lineNumber($lineStarts, $offset),
            'code' => 'unsupported-interpolation',
        ];
    }

    private static function validTail(string $source, int $start, int $end): bool
    {
        $tail = ltrim(substr($source, $start, $end - $start), " \t");

        return $tail === '' || str_starts_with($tail, '#');
    }

    /** @return array{int, int} */
    private static function physicalLine(string $source, int $start): array
    {
        $length = strlen($source);
        $end = $start;
        while ($end < $length && $source[$end] !== "\r" && $source[$end] !== "\n") {
            $end++;
        }
        $next = $end;
        if ($next < $length && $source[$next] === "\r") {
            $next++;
            if (($source[$next] ?? null) === "\n") {
                $next++;
            }
        } elseif ($next < $length) {
            $next++;
        }

        return [$end, $next];
    }

    private static function nextPhysicalLine(string $source, int $offset): int
    {
        return self::physicalLine($source, $offset)[1];
    }

    /** @return list<int> */
    private static function lineStarts(string $source): array
    {
        $starts = [0];
        $length = strlen($source);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($source[$offset] === "\r") {
                if (($offset + 1) < $length && $source[$offset + 1] === "\n") {
                    $offset++;
                }
                $starts[] = $offset + 1;
            } elseif ($source[$offset] === "\n") {
                $starts[] = $offset + 1;
            }
        }

        return $starts;
    }

    /** @param list<int> $lineStarts */
    private static function lineNumber(array $lineStarts, int $offset): int
    {
        $low = 0;
        $high = count($lineStarts) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($lineStarts[$middle] <= $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $high + 1;
    }

    /**
     * @param array{name: string, start: int, end: int, line: int} $location
     * @return array{name: string, file: string, start: int, end: int, line: int, contentHash: string, confidence: string}
     */
    private static function location(array $location, string $file, string $hash): array
    {
        return [
            'name' => $location['name'],
            'file' => $file,
            'start' => $location['start'],
            'end' => $location['end'],
            'line' => $location['line'],
            'contentHash' => $hash,
            'confidence' => 'known-positive',
        ];
    }

    /** @return array{file: string|null, code: string, message: string} */
    private static function error(?string $file, string $code): array
    {
        $message = match ($code) {
            'invalid-source' => 'Source must be an explicit project-relative environment template.',
            'unsupported-source' => 'Resolved source must be named .env.example or .env.template.',
            'source-limit' => 'Source exceeds the bounded read limit.',
            default => 'Source could not be read.',
        };

        return ['file' => $file, 'code' => $code, 'message' => $message];
    }

    /** @return array{file: string, line: int, code: string, message: string} */
    private static function sourceNotice(string $file, int $line, string $code, bool $uncertain): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'code' => $code,
            'message' => $uncertain
                ? 'Interpolation-like syntax outside the supported literal ${NAME} form was not exported.'
                : 'The dotenv entry is outside the supported source-only grammar.',
        ];
    }
}
