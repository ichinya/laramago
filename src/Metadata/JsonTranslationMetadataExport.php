<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

/**
 * Literal keys from explicitly selected Laravel JSON translation files.
 *
 * Laravel's native file loader decodes each locale JSON file as an associative
 * array before merging paths. This exporter retains every root object key for
 * navigation while marking which duplicate survives that per-file decode. It
 * does not discover loader paths, locales, fallbacks, or an effective catalog.
 */
final class JsonTranslationMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1048576;
    private const MAX_TOTAL_BYTES = 8388608;
    private const MAX_DECLARATIONS = 20000;
    private const JSON_DEPTH = 512;

    /**
     * @param array<array-key, string> $files
     * @return array{
     *   schemaVersion: int, projectRoot: string, scope: array{kind: string, evidence: string},
     *   declarations: list<array{file: string, name: string, phpKey: string|int,
     *     start: int, end: int, line: int, contentHash: string,
     *     sourceSelected: bool, confidence: string}>,
     *   errors: list<array{file: string, code: string, message: string}>,
     *   truncated: bool, truncationReasons: list<string>
     * }
     */
    public function export(string $root, array $files): array
    {
        clearstatcache(true);
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException('Project root must be an existing directory.');
        }
        if (! array_is_list($files)) {
            throw new \InvalidArgumentException('JSON translation sources must be a list of project-relative files.');
        }

        $root = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($root, '/').'/';
        $declarations = [];
        $errors = [];
        $reasons = [];
        $totalBytes = 0;
        $requested = [];
        $resolvedFiles = [];
        if (count($files) > self::MAX_FILES) {
            $reasons['file-limit'] = true;
        }

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $file = str_replace('\\', '/', $file);
            if (isset($requested[$file])) {
                continue;
            }
            $requested[$file] = true;

            $code = null;
            if (
                $file === ''
                || str_starts_with($file, '/')
                || str_contains($file, ':')
                || str_contains($file, "\0")
                || in_array('..', explode('/', $file), true)
            ) {
                $code = 'invalid-source-path';
            } elseif (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'json') {
                $code = 'unsupported-source-format';
            }

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
                } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
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
            if (isset($resolvedFiles[$identity])) {
                continue;
            }
            $resolvedFiles[$identity] = true;

            $remaining = max(0, self::MAX_TOTAL_BYTES - $totalBytes);
            $contents = @file_get_contents($path, false, null, 0, min(self::MAX_FILE_BYTES, $remaining) + 1);
            if ($contents === false) {
                $errors[] = self::error($file, 'unreadable-source');
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($size > self::MAX_FILE_BYTES) {
                $reasons['file-byte-limit'] = true;
                $errors[] = self::error($file, 'source-byte-limit');
                continue;
            }
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error($file, 'source-byte-limit');
                break;
            }

            try {
                json_decode($contents, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $errors[] = self::error($file, 'parse-failure');
                continue;
            }

            $offset = strspn($contents, " \t\r\n");
            if (($contents[$offset] ?? null) !== '{') {
                $errors[] = self::error($file, 'unsupported-root');
                continue;
            }

            [$fileDeclarations, $declarationsTruncated] = self::rootKeys(
                $contents,
                $path,
                hash('sha256', $contents),
                self::MAX_DECLARATIONS - count($declarations),
            );
            array_push($declarations, ...$fileDeclarations);
            if ($declarationsTruncated) {
                $reasons['declaration-limit'] = true;
                break;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $root,
            'scope' => ['kind' => 'translations-json', 'evidence' => 'source-only'],
            'declarations' => $declarations,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * The document has already been validated. This pass only locates root key
     * literals and skips complete values, retaining duplicate source evidence.
     *
     * @return array{list<array{file: string, name: string, phpKey: string|int,
     *   start: int, end: int, line: int, contentHash: string,
     *   sourceSelected: bool, confidence: string}>, bool}
     */
    private static function rootKeys(string $contents, string $file, string $hash, int $limit): array
    {
        $length = strlen($contents);
        $offset = strspn($contents, " \t\r\n") + 1;
        $declarations = [];
        $selected = [];
        $truncated = false;

        while ($offset < $length) {
            $offset += strspn($contents, " \t\r\n", $offset);
            if (($contents[$offset] ?? null) === '}') {
                break;
            }

            $start = $offset;
            $end = self::stringEnd($contents, $offset);
            $literal = substr($contents, $start, $end - $start);
            /** @var string $name */
            $name = json_decode($literal, true, 2, JSON_THROW_ON_ERROR);
            $phpKey = array_key_first([$name => true]);
            $identity = serialize($phpKey);
            if (array_key_exists($identity, $selected) && $selected[$identity] !== null) {
                $declarations[$selected[$identity]]['sourceSelected'] = false;
            }
            if (count($declarations) < $limit) {
                $selected[$identity] = count($declarations);
                $declarations[] = [
                    'file' => $file,
                    'name' => $name,
                    'phpKey' => $phpKey,
                    'start' => $start,
                    'end' => $end,
                    'line' => substr_count(substr($contents, 0, $start), "\n") + 1,
                    'contentHash' => $hash,
                    'sourceSelected' => true,
                    'confidence' => 'known-positive',
                ];
            } else {
                if (array_key_exists($identity, $selected)) {
                    $selected[$identity] = null;
                }
                $truncated = true;
            }

            $offset = $end;
            $offset += strspn($contents, " \t\r\n", $offset);
            $offset++;
            $offset += strspn($contents, " \t\r\n", $offset);
            $offset = self::valueEnd($contents, $offset);
            $offset += strspn($contents, " \t\r\n", $offset);
            if (($contents[$offset] ?? null) === ',') {
                $offset++;
            }
        }

        return [$declarations, $truncated];
    }

    private static function stringEnd(string $contents, int $offset): int
    {
        $length = strlen($contents);
        $offset++;
        while ($offset < $length) {
            if ($contents[$offset] === '\\') {
                $offset += 2;
                continue;
            }
            if ($contents[$offset] === '"') {
                return $offset + 1;
            }
            $offset++;
        }

        return $length;
    }

    private static function valueEnd(string $contents, int $offset): int
    {
        $length = strlen($contents);
        $depth = 0;
        while ($offset < $length) {
            $character = $contents[$offset];
            if ($character === '"') {
                $offset = self::stringEnd($contents, $offset);
                continue;
            }
            if ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === ']' || $character === '}' && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && ($character === ',' || $character === '}')) {
                break;
            }
            $offset++;
        }

        return $offset;
    }

    /** @return array{file: string, code: string, message: string} */
    private static function error(string $file, string $code): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'message' => match ($code) {
                'invalid-source-path' => 'Source must be a project-relative JSON file within the project root.',
                'unsupported-source-format' => 'Only JSON translation sources are supported.',
                'parse-failure' => 'Unable to parse JSON translation source.',
                'unsupported-root' => 'JSON translation source must contain one root object.',
                'source-byte-limit' => 'JSON translation source exceeds the bounded read budget.',
                default => 'Unable to read JSON translation source.',
            },
        ];
    }
}
