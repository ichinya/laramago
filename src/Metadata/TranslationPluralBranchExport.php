<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Branch-shape candidates from explicitly selected PHP translation sources.
 *
 * Laravel's native selector accepts permissive and locale-dependent forms and
 * may be replaced at runtime. This export therefore describes literal source
 * structure only. It never reports invalid syntax or an effective selection.
 */
final class TranslationPluralBranchExport
{
    private const MAX_FILES = 256;
    private const MAX_BYTES = 1048576;
    private const MAX_TOTAL_BYTES = 8388608;
    private const MAX_CANDIDATES = 20000;
    private const MAX_BRANCHES = 20000;
    private const MAX_OUTPUT_BYTES = 8388608;
    private const MAX_DEPTH = 32;

    /**
     * @param array<array-key, string> $files
     * @return array{
     *   schemaVersion: int, projectRoot: string,
     *   scope: array{
     *     kind: string, evidence: string, semantics: string, exhaustive: bool,
     *     advisoryOnly: bool, effectiveLocale: string, selectorIdentity: string,
     *     runtimeSelection: string
     *   },
     *   candidates: list<array{
     *     file: string, segments: list<string|int>, messageName: string|int,
     *     messagePhpKey: string|int, branchCount: int, branchesComplete: bool,
     *     branches: list<array{
     *       index: int, conditionPrefixRecognized: bool,
     *       conditionKind: string, delimiterPair: string|null,
     *       conditionCommaCount: int|null, payloadState: string
     *     }>,
     *     messageStart: int, messageEnd: int, messageLine: int,
     *     keyStart: int, keyEnd: int, keyLine: int, spanKind: string,
     *     contentHash: string, sourceSelected: bool, confidence: string
     *   }>,
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
            throw new \InvalidArgumentException('Translation sources must be a list of project-relative PHP files.');
        }
        $root = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($root, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $candidates = [];
        $errors = [];
        $reasons = [];
        $bytes = 0;
        $branchMetadataCount = 0;
        $outputBytes = 0;
        $seen = [];
        $resolvedFiles = [];
        if (count($files) > self::MAX_FILES) {
            $reasons['file-limit'] = true;
        }
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $file = str_replace('\\', '/', $file);
            if (isset($seen[$file])) {
                continue;
            }
            $seen[$file] = true;
            $code = null;
            if (
                $file === ''
                || str_starts_with($file, '/')
                || str_contains($file, ':')
                || str_contains($file, "\0")
                || in_array('..', explode('/', $file), true)
            ) {
                $code = 'invalid-source-path';
            } elseif (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                $code = 'unsupported-source-format';
            }
            $path = $code === null ? realpath($prefix.$file) : false;
            if ($code === null && $path === false) {
                $code = 'unreadable-source';
            }
            if ($path !== false) {
                $path = str_replace('\\', '/', $path);
                $inside = DIRECTORY_SEPARATOR === '\\'
                    ? str_starts_with(strtolower($path), strtolower($prefix))
                    : str_starts_with($path, $prefix);
                if (! $inside) {
                    $code = 'invalid-source-path';
                } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
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
            $readLimit = min(self::MAX_BYTES, self::MAX_TOTAL_BYTES - $bytes) + 1;
            $contents = @file_get_contents($path, false, null, 0, $readLimit);
            if ($contents === false) {
                $errors[] = self::error($file, 'unreadable-source');
                continue;
            }
            $bytes += strlen($contents);
            if (strlen($contents) > self::MAX_BYTES || $bytes > self::MAX_TOTAL_BYTES) {
                $reasons['source-byte-limit'] = true;
                $errors[] = self::error($file, 'source-byte-limit');
                if ($bytes > self::MAX_TOTAL_BYTES) {
                    break;
                }
                continue;
            }
            try {
                $nodes = $parser->parse($contents) ?? [];
            } catch (Error) {
                $errors[] = self::error($file, 'parse-failure');
                continue;
            }
            $returned = self::returnedArray($nodes);
            if ($returned === null) {
                $errors[] = self::error($file, 'unsupported-return');
                continue;
            }
            self::collect(
                $returned,
                [],
                true,
                $path,
                hash('sha256', $contents),
                $candidates,
                $reasons,
                $branchMetadataCount,
                $outputBytes,
            );
            if (isset($reasons['candidate-limit']) || isset($reasons['output-byte-limit'])) {
                break;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $root,
            'scope' => [
                'kind' => 'translation-plural-branches',
                'evidence' => 'source-only',
                'semantics' => 'plural-branch-structure-candidates',
                'exhaustive' => false,
                'advisoryOnly' => true,
                'effectiveLocale' => 'unknown',
                'selectorIdentity' => 'unknown',
                'runtimeSelection' => 'unknown',
            ],
            'candidates' => $candidates,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /** @param array<array-key, Node> $nodes */
    private static function returnedArray(array $nodes): ?Node\Expr\Array_
    {
        $returned = null;
        foreach ($nodes as $node) {
            if (
                $node instanceof Node\Stmt\Return_
                && $returned === null
                && $node->expr instanceof Node\Expr\Array_
            ) {
                $returned = $node->expr;
            } elseif (
                ! $node instanceof Node\Stmt\Nop
                && ! $node instanceof Node\Stmt\Use_
                && ! $node instanceof Node\Stmt\GroupUse
                && ! ($node instanceof Node\Stmt\Declare_
                && $node->stmts === null)
            ) {
                return null;
            }
        }

        return $returned;
    }

    /**
     * @param list<string|int> $parents
     * @param list<array{
     *   file: string, segments: list<string|int>, messageName: string|int,
     *   messagePhpKey: string|int, branchCount: int, branchesComplete: bool,
     *   branches: list<array{
     *     index: int, conditionPrefixRecognized: bool,
     *     conditionKind: string, delimiterPair: string|null,
     *     conditionCommaCount: int|null, payloadState: string
     *   }>,
     *   messageStart: int, messageEnd: int, messageLine: int,
     *   keyStart: int, keyEnd: int, keyLine: int, spanKind: string,
     *   contentHash: string, sourceSelected: bool, confidence: string
     * }> $candidates
     * @param array<string, bool> $reasons
     */
    private static function collect(
        Node\Expr\Array_ $array,
        array $parents,
        bool $parentSelected,
        string $file,
        string $hash,
        array &$candidates,
        array &$reasons,
        int &$branchMetadataCount,
        int &$outputBytes,
    ): void {
        if (count($parents) >= self::MAX_DEPTH) {
            $reasons['depth-limit'] = true;

            return;
        }
        $seen = [];
        $unknown = false;
        foreach (array_reverse($array->items) as $item) {
            $keyNode = $item->key;
            $key = self::literalKey($keyNode);
            if ($item->unpack || $key === null || $keyNode === null) {
                $unknown = true;
                continue;
            }
            $normalized = array_key_first([$key => true]);
            $selected = $parentSelected && ! $unknown && ! isset($seen[$normalized]) && ! $item->byRef;
            $seen[$normalized] = true;
            $segments = [...$parents, $key];
            if ($item->value instanceof Node\Expr\Array_) {
                self::collect(
                    $item->value,
                    $segments,
                    $selected,
                    $file,
                    $hash,
                    $candidates,
                    $reasons,
                    $branchMetadataCount,
                    $outputBytes,
                );
                if (isset($reasons['candidate-limit']) || isset($reasons['output-byte-limit'])) {
                    return;
                }
                continue;
            }
            if (! $item->value instanceof Node\Scalar\String_) {
                continue;
            }
            $parts = explode('|', $item->value->value);
            $firstCondition = self::conditionShape($parts[0]);
            if (count($parts) === 1 && $firstCondition === null) {
                continue;
            }
            if (count($candidates) >= self::MAX_CANDIDATES) {
                $reasons['candidate-limit'] = true;

                return;
            }
            $branches = [];
            foreach ($parts as $index => $part) {
                if ($branchMetadataCount >= self::MAX_BRANCHES) {
                    $reasons['branch-limit'] = true;
                    break;
                }
                $condition =
                    $index === 0 && $firstCondition !== null
                        ? $firstCondition
                        : self::conditionShape($part);
                $payload = $condition === null ? $part : $condition['payload'];
                $branches[] = [
                    'index' => $index,
                    'conditionPrefixRecognized' => $condition !== null,
                    'conditionKind' => $condition['kind'] ?? 'none',
                    'delimiterPair' => $condition['delimiterPair'] ?? null,
                    'conditionCommaCount' => $condition['commaCount'] ?? null,
                    'payloadState' => $payload === ''
                        ? 'empty'
                        : (trim($payload) === '' ? 'whitespace-only' : 'present'),
                ];
                $branchMetadataCount++;
            }
            $candidate = [
                'file' => $file,
                'segments' => $segments,
                'messageName' => $key,
                'messagePhpKey' => $normalized,
                'branchCount' => count($parts),
                'branchesComplete' => count($branches) === count($parts),
                'branches' => $branches,
                'messageStart' => $item->value->getStartFilePos(),
                'messageEnd' => $item->value->getEndFilePos() + 1,
                'messageLine' => $item->value->getStartLine(),
                'keyStart' => $keyNode->getStartFilePos(),
                'keyEnd' => $keyNode->getEndFilePos() + 1,
                'keyLine' => $keyNode->getStartLine(),
                'spanKind' => 'message-literal',
                'contentHash' => $hash,
                'sourceSelected' => $selected,
                'confidence' => 'branch-structure-candidate',
            ];
            $candidateBytes = 1024 + (6 * strlen($file)) + (384 * count($branches));
            foreach ($segments as $segment) {
                $candidateBytes += 6 * strlen((string) $segment);
            }
            if (($outputBytes + $candidateBytes) > self::MAX_OUTPUT_BYTES) {
                $reasons['output-byte-limit'] = true;

                return;
            }
            $outputBytes += $candidateBytes;
            $candidates[] = $candidate;
        }
    }

    /**
     * Match only the prefix shape recognized by the pinned native selector.
     * Delimiter pairing, empty conditions, repeated commas, overlaps and
     * incomplete locale forms remain data rather than errors.
     *
     * @return array{kind: string, delimiterPair: string, commaCount: int, payload: string}|null
     */
    private static function conditionShape(string $part): ?array
    {
        $matches = [];
        if (! preg_match('/^[\{\[]([-?\d|*,\.*]*)[\}\]](.*)/s', $part, $matches)) {
            return null;
        }

        return [
            'kind' => str_contains($matches[1], ',') ? 'range' : 'single',
            'delimiterPair' => $part[0].$part[strlen($matches[1]) + 1],
            'commaCount' => substr_count($matches[1], ','),
            'payload' => $matches[2],
        ];
    }

    private static function literalKey(?Node\Expr $node): string|int|null
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\UnaryMinus && $node->expr instanceof Node\Scalar\Int_) {
            return -$node->expr->value;
        }

        return null;
    }

    /** @return array{file: string, code: string, message: string} */
    private static function error(string $file, string $code): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'message' => match ($code) {
                'invalid-source-path' => 'Source must be a project-relative file within the project root.',
                'unsupported-source-format' => 'Only PHP translation sources are supported; JSON is deferred.',
                'parse-failure' => 'Unable to parse PHP translation source.',
                'unsupported-return' => 'Source must directly return one literal PHP array.',
                'source-byte-limit' => 'PHP source exceeds the bounded read budget.',
                default => 'Unable to read PHP translation source.',
            },
        ];
    }
}
