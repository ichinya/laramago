<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

/** Explicit PHP files only; declarations are not Laravel locale or fallback resolution. */
final class TranslationMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_BYTES = 1048576;
    private const MAX_TOTAL_BYTES = 8388608;
    private const MAX_DECLARATIONS = 20000;
    private const MAX_DEPTH = 32;

    /**
     * @param list<string> $files
     * @return array{
     *   schemaVersion: int, projectRoot: string, scope: array{kind: string, evidence: string},
     *   declarations: list<array{file: string, segments: list<string|int>, name: string|int,
     *     phpKey: string|int, start: int, end: int, line: int, contentHash: string,
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
        $root = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($root, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $declarations = [];
        $errors = [];
        $reasons = [];
        $bytes = 0;
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
                    $returned = null;
                    break;
                }
            }
            if ($returned === null) {
                $errors[] = self::error($file, 'unsupported-return');
                continue;
            }
            self::collect($returned, [], true, $path, hash('sha256', $contents), $declarations, $reasons);
            if (isset($reasons['declaration-limit'])) {
                break;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $root,
            'scope' => ['kind' => 'translations', 'evidence' => 'source-only'],
            'declarations' => $declarations,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * Retain every explicit declaration, including overwritten duplicates. Later unknown
     * keys/unpacks make earlier selections uncertain. Implicit indices are not declarations.
     * @param list<string|int> $parents
     * @param list<array{file: string, segments: list<string|int>, name: string|int,
     *   phpKey: string|int, start: int, end: int, line: int, contentHash: string,
     *   sourceSelected: bool, confidence: string}> $declarations
     * @param array<string, bool> $reasons
     */
    private static function collect(
        Node\Expr\Array_ $array,
        array $parents,
        bool $parentSelected,
        string $file,
        string $hash,
        array &$declarations,
        array &$reasons,
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
            if (count($declarations) >= self::MAX_DECLARATIONS) {
                $reasons['declaration-limit'] = true;

                return;
            }
            // Native array indexing applies PHP's decimal string-to-integer key coercion.
            $normalized = array_key_first([$key => true]);
            $selected = $parentSelected && ! $unknown && ! isset($seen[$normalized]) && ! $item->byRef;
            $seen[$normalized] = true;
            $segments = [...$parents, $key];
            $declarations[] = [
                'file' => $file,
                'segments' => $segments,
                'name' => $key,
                'phpKey' => $normalized,
                'start' => $keyNode->getStartFilePos(),
                'end' => $keyNode->getEndFilePos() + 1,
                'line' => $keyNode->getStartLine(),
                'contentHash' => $hash,
                'sourceSelected' => $selected,
                'confidence' => 'known-positive',
            ];
            if ($item->value instanceof Node\Expr\Array_) {
                self::collect($item->value, $segments, $selected, $file, $hash, $declarations, $reasons);
            }
        }
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
