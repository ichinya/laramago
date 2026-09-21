<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

/**
 * Direct `import.meta.env.NAME` candidates from explicitly selected JS/TS files.
 *
 * This is a bounded lexical export, not a JavaScript parser. It skips comments and
 * quoted strings, and stops at constructs whose lexical role cannot be established
 * safely by this dependency-free scanner. It never reads dotenv files or values.
 */
final class ViteEnvironmentReferences
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 20000;
    private const MAX_TOKENS = 200000;

    /**
     * @param array<array-key, string> $files Project-relative JavaScript or TypeScript source files.
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
            throw new \InvalidArgumentException('Vite sources must be a list of project-relative files.');
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
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
            $file = str_replace('\\', '/', $file);
            $code = self::sourceError($file);
            $path = $code === null ? realpath($prefix.$file) : false;
            if ($code === null && $path === false) {
                $code = 'unreadable-source';
            }
            if ($path !== false) {
                $path = str_replace('\\', '/', $path);
                $contained = DIRECTORY_SEPARATOR === '\\'
                    ? str_starts_with(strtolower($path), strtolower($prefix))
                    : str_starts_with($path, $prefix);
                if (! $contained) {
                    $code = 'invalid-source-path';
                } elseif (! self::supportedExtension($path)) {
                    $code = 'unsupported-source-format';
                } elseif (! is_file($path)) {
                    $code = 'unreadable-source';
                }
            }
            if ($code !== null || $path === false) {
                $errors[] = self::error($file, $code ?? 'unreadable-source');
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
            $size = strlen($contents);
            $totalBytes += $size;
            if ($size > self::MAX_FILE_BYTES || $totalBytes > self::MAX_TOTAL_BYTES) {
                $reason = $totalBytes > self::MAX_TOTAL_BYTES ? 'total-byte-limit' : 'file-byte-limit';
                $reasons[$reason] = true;
                $errors[] = self::error($file, 'source-byte-limit');
                if ($reason === 'total-byte-limit') {
                    break;
                }
                continue;
            }

            $lexed = self::lex($contents, $path);
            if ($lexed['error'] !== null) {
                $errors[] = self::error($file, $lexed['error']);
                continue;
            }
            foreach ($lexed['uncertainties'] as $uncertainty) {
                $uncertainties[] = $uncertainty;
                $reasons[$uncertainty['code']] = true;
            }

            $hash = hash('sha256', $contents);
            foreach (self::references($lexed['tokens'], $path, $hash, $uncertainties, $reasons) as $reference) {
                if (count($references) >= self::MAX_REFERENCES) {
                    $reasons['reference-limit'] = true;
                    break 2;
                }
                $references[] = $reference;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'vite-environment-references',
                'evidence' => 'source-lexer',
                'syntax' => 'direct-dot-property',
                'completeness' => 'non-exhaustive',
            ],
            'references' => $references,
            'errors' => $errors,
            'uncertainties' => $uncertainties,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function sourceError(string $file): ?string
    {
        if (
            $file === ''
            || str_starts_with($file, '/')
            || str_contains($file, ':')
            || str_contains($file, "\0")
            || in_array('..', explode('/', $file), true)
        ) {
            return 'invalid-source-path';
        }

        return self::supportedExtension($file) ? null : 'unsupported-source-format';
    }

    private static function supportedExtension(string $file): bool
    {
        return preg_match('/\.(?:js|mjs|cjs|ts|mts|cts)$/i', $file) === 1;
    }

    /**
     * @return array{
     *   tokens: list<array{type: string, value: string, start: int, end: int, line: int}>,
     *   uncertainties: list<array{file: string, code: string, offset: int, line: int, message: string}>,
     *   error: ?string
     * }
     */
    private static function lex(string $source, string $file): array
    {
        $tokens = [];
        $uncertainties = [];
        $length = strlen($source);
        $offset = 0;
        $line = 1;

        if (str_starts_with($source, '#!')) {
            while ($offset < $length && $source[$offset] !== "\r" && $source[$offset] !== "\n") {
                $offset++;
            }
        }

        while ($offset < $length) {
            if (count($tokens) >= self::MAX_TOKENS) {
                $uncertainties[] = self::uncertainty($file, 'token-limit', $offset, $line);
                break;
            }
            $character = $source[$offset];
            if ($character === "\n") {
                $line++;
                $offset++;
                continue;
            }
            if ($character === "\r") {
                $line++;
                $offset += ($source[$offset + 1] ?? '') === "\n" ? 2 : 1;
                continue;
            }
            if ($character === ' ' || $character === "\t" || $character === "\v" || $character === "\f") {
                $offset++;
                continue;
            }

            if (
                substr_compare($source, '<!--', $offset, 4) === 0
                || substr_compare($source, '-->', $offset, 3) === 0
            ) {
                while ($offset < $length && $source[$offset] !== "\r" && $source[$offset] !== "\n") {
                    $offset++;
                }
                continue;
            }

            if ($character === '/' && ($source[$offset + 1] ?? '') === '/') {
                $offset += 2;
                while ($offset < $length && $source[$offset] !== "\r" && $source[$offset] !== "\n") {
                    $offset++;
                }
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $offset + 2);
                if ($end === false) {
                    return ['tokens' => [], 'uncertainties' => [], 'error' => 'lexical-failure'];
                }
                self::advanceLines($source, $offset, $end + 2, $line);
                $offset = $end + 2;
                continue;
            }
            if ($character === '/') {
                $uncertainties[] = self::uncertainty($file, 'ambiguous-slash-token', $offset, $line);
                break;
            }
            if ($character === '`') {
                $uncertainties[] = self::uncertainty($file, 'template-literal', $offset, $line);
                break;
            }
            if ($character === '<') {
                $uncertainties[] = self::uncertainty($file, 'ambiguous-angle-token', $offset, $line);
                break;
            }
            if ($character === "'" || $character === '"') {
                $start = $offset;
                $startLine = $line;
                if (! self::skipQuoted($source, $character, $offset, $line)) {
                    return ['tokens' => [], 'uncertainties' => [], 'error' => 'lexical-failure'];
                }
                $tokens[] = [
                    'type' => 'string',
                    'value' => '',
                    'start' => $start,
                    'end' => $offset,
                    'line' => $startLine,
                ];
                continue;
            }

            $byte = ord($character);
            if ($character === '\\' || $byte === 0 || $byte >= 128) {
                $uncertainties[] = self::uncertainty($file, 'unsupported-lexical-token', $offset, $line);
                break;
            }

            if (self::identifierStart($character)) {
                $start = $offset++;
                while ($offset < $length && self::identifierPart($source[$offset])) {
                    $offset++;
                }
                if ($offset < $length && ($source[$offset] === '\\' || ord($source[$offset]) >= 128)) {
                    $uncertainties[] = self::uncertainty($file, 'unsupported-lexical-token', $start, $line);
                    break;
                }
                $tokens[] = [
                    'type' => 'identifier',
                    'value' => substr($source, $start, $offset - $start),
                    'start' => $start,
                    'end' => $offset,
                    'line' => $line,
                ];
            } else {
                $tokens[] = [
                    'type' => 'punctuator',
                    'value' => $character,
                    'start' => $offset,
                    'end' => $offset + 1,
                    'line' => $line,
                ];
                $offset++;
            }

            if (count($tokens) >= self::MAX_TOKENS) {
                $uncertainties[] = self::uncertainty($file, 'token-limit', $offset, $line);
                break;
            }
        }

        return ['tokens' => $tokens, 'uncertainties' => $uncertainties, 'error' => null];
    }

    private static function skipQuoted(string $source, string $quote, int &$offset, int &$line): bool
    {
        $length = strlen($source);
        $offset++;
        while ($offset < $length) {
            $character = $source[$offset];
            if ($character === $quote) {
                $offset++;

                return true;
            }
            if ($character === "\r" || $character === "\n") {
                return false;
            }
            if ($character !== '\\') {
                $offset++;
                continue;
            }
            $offset++;
            if ($offset >= $length) {
                return false;
            }
            if ($source[$offset] === "\n") {
                $line++;
                $offset++;
            } elseif ($source[$offset] === "\r") {
                $line++;
                $offset += ($source[$offset + 1] ?? '') === "\n" ? 2 : 1;
            } else {
                $offset++;
            }
        }

        return false;
    }

    private static function advanceLines(string $source, int $start, int $end, int &$line): void
    {
        for ($offset = $start; $offset < $end; $offset++) {
            if ($source[$offset] === "\n") {
                $line++;
            } elseif ($source[$offset] === "\r" && ($source[$offset + 1] ?? '') !== "\n") {
                $line++;
            }
        }
    }

    private static function identifierStart(string $character): bool
    {
        return (
            $character >= 'a'
            && $character <= 'z'
            || $character >= 'A'
            && $character <= 'Z'
            || $character === '_'
            || $character === '$'
        );
    }

    private static function identifierPart(string $character): bool
    {
        return self::identifierStart($character) || $character >= '0' && $character <= '9';
    }

    /**
     * @param list<array{type: string, value: string, start: int, end: int, line: int}> $tokens
     * @param list<array{file: string, code: string, offset: int, line: int, message: string}> $uncertainties
     * @param array<string, true> $reasons
     * @return \Generator<int, array{name: string, file: string, start: int, end: int, line: int,
     *   contentHash: string, confidence: string, syntax: string}>
     */
    private static function references(
        array $tokens,
        string $file,
        string $hash,
        array &$uncertainties,
        array &$reasons,
    ): \Generator {
        $count = count($tokens);
        for ($index = 0; ($index + 4) < $count; $index++) {
            if (
                ! self::isToken($tokens[$index], 'identifier', 'import')
                || $index > 0
                && self::isToken($tokens[$index - 1], 'punctuator', '.')
                || ! self::isToken($tokens[$index + 1], 'punctuator', '.')
                || ! self::isToken($tokens[$index + 2], 'identifier', 'meta')
                || ! self::isToken($tokens[$index + 3], 'punctuator', '.')
                || ! self::isToken($tokens[$index + 4], 'identifier', 'env')
            ) {
                continue;
            }

            if (
                isset($tokens[$index + 6])
                && self::isToken($tokens[$index + 5], 'punctuator', '.')
                && $tokens[$index + 6]['type'] === 'identifier'
            ) {
                $name = $tokens[$index + 6];
                yield [
                    'name' => $name['value'],
                    'file' => $file,
                    'start' => $name['start'],
                    'end' => $name['end'],
                    'line' => $name['line'],
                    'contentHash' => $hash,
                    'confidence' => 'completion-candidate',
                    'syntax' => 'direct-dot-property',
                ];
                $index += 6;
                continue;
            }

            if (isset($tokens[$index + 5]) && in_array($tokens[$index + 5]['value'], ['[', '?'], true)) {
                $uncertainty = self::uncertainty(
                    $file,
                    'unsupported-environment-access',
                    $tokens[$index + 5]['start'],
                    $tokens[$index + 5]['line'],
                );
                $uncertainties[] = $uncertainty;
                $reasons[$uncertainty['code']] = true;
            }
        }
    }

    /** @param array{type: string, value: string, start: int, end: int, line: int} $token */
    private static function isToken(array $token, string $type, string $value): bool
    {
        return $token['type'] === $type && $token['value'] === $value;
    }

    /** @return array{file: string, code: string, offset: int, line: int, message: string} */
    private static function uncertainty(string $file, string $code, int $offset, int $line): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'offset' => $offset,
            'line' => $line,
            'message' => match ($code) {
                'ambiguous-slash-token' => 'Scanning stopped before a division or regular-expression token.',
                'ambiguous-angle-token' => 'Scanning stopped before possible JSX or TypeScript angle syntax.',
                'template-literal' => 'Scanning stopped before a template literal.',
                'unsupported-environment-access' => 'Only direct dot-property environment names are exported.',
                'token-limit' => 'Lexical token limit reached.',
                default => 'Scanning stopped before an unsupported lexical token.',
            },
        ];
    }

    /** @return array{file: string, code: string, message: string} */
    private static function error(string $file, string $code): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'message' => match ($code) {
                'invalid-source-path' => 'Source must be a project-relative file contained in the project.',
                'unsupported-source-format' => 'Source must use a supported JavaScript or TypeScript extension.',
                'source-byte-limit' => 'Source exceeds the bounded read budget.',
                'lexical-failure' => 'Source contains an unterminated comment or quoted string.',
                default => 'Unable to read source.',
            },
        ];
    }
}
