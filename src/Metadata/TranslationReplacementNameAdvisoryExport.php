<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Optional naming advice for literal translation replacement dictionary keys.
 *
 * Laravel accepts arbitrary scalar replacement keys. This exporter only applies
 * the explicitly requested ASCII colon-word convention used by translation
 * placeholder completion. Every finding is a review candidate, never proof of
 * an invalid call, missing replacement, or ineffective runtime replacement.
 */
final class TranslationReplacementNameAdvisoryExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1048576;
    private const MAX_TOTAL_BYTES = 8388608;
    private const MAX_ADVISORIES = 20000;
    private const MAX_UNCERTAINTIES = 20000;
    private const MAX_OUTPUT_BYTES = 8388608;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP call-site sources.
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
                'Translation replacement call sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $advisories = [];
        $uncertainties = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $selection = [
            'candidateCalls' => 0,
            'literalReplacementKeys' => 0,
            'advisoryCandidates' => 0,
        ];
        $totalBytes = 0;
        $outputBytes = 0;
        $seen = [];

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

            try {
                $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse($contents) ?? []);
            } catch (Error) {
                $errors[] = self::error($file, 'parse-failure');
                continue;
            }

            $hash = hash('sha256', $contents);
            /** @var list<Node\Expr\FuncCall|Node\Expr\StaticCall> $calls */
            $calls = $finder->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\FuncCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            );
            usort(
                $calls,
                static fn (Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos(),
            );

            foreach ($calls as $call) {
                $shape = self::replacementCall($call);
                if ($shape === null) {
                    continue;
                }
                $selection['candidateCalls']++;
                $argument = self::argument($call->args, $shape['replacementIndex']);
                if ($argument === null) {
                    continue;
                }
                if (! $argument->value instanceof Node\Expr\Array_) {
                    if (! self::appendUncertainty(
                        $uncertainties,
                        $reasons,
                        $outputBytes,
                        $path,
                        $hash,
                        $argument->value,
                        'non-literal-replacement-array',
                    )) {
                        break 2;
                    }
                    continue;
                }

                $unknownSelection = false;
                $seenKeys = [];
                foreach (array_reverse($argument->value->items) as $item) {
                    if ($item->unpack || $item->key === null) {
                        $unknownSelection = true;
                        if (! self::appendUncertainty(
                            $uncertainties,
                            $reasons,
                            $outputBytes,
                            $path,
                            $hash,
                            $item,
                            $item->unpack ? 'unpacked-replacement-entries' : 'implicit-numeric-replacement-key',
                        )) {
                            break 3;
                        }
                        continue;
                    }
                    $name = self::literalKey($item->key);
                    if ($name === null) {
                        $unknownSelection = true;
                        if (! self::appendUncertainty(
                            $uncertainties,
                            $reasons,
                            $outputBytes,
                            $path,
                            $hash,
                            $item->key,
                            'dynamic-replacement-key',
                        )) {
                            break 3;
                        }
                        continue;
                    }

                    $selection['literalReplacementKeys']++;
                    $phpKey = array_key_first([$name => true]);
                    $selected = ! $unknownSelection && ! isset($seenKeys[$phpKey]) && ! $item->byRef;
                    $seenKeys[$phpKey] = true;
                    if (preg_match('/^[A-Za-z0-9_]+$/D', (string) $phpKey) === 1) {
                        continue;
                    }
                    if (count($advisories) >= self::MAX_ADVISORIES) {
                        $reasons['advisory-limit'] = true;
                        break 3;
                    }

                    $advisory = [
                        'name' => $name,
                        'phpKey' => $phpKey,
                        'file' => $path,
                        'start' => $item->key->getStartFilePos(),
                        'end' => $item->key->getEndFilePos() + 1,
                        'line' => $item->key->getStartLine(),
                        'callStart' => $call->getStartFilePos(),
                        'callEnd' => $call->getEndFilePos() + 1,
                        'callLine' => $call->getStartLine(),
                        'contentHash' => $hash,
                        'syntax' => $shape['syntax'],
                        'reason' => 'outside-ascii-colon-word-convention',
                        'sourceSelected' => $selected,
                        'confidence' => 'optional-style-review-candidate',
                        'runtimeValidity' => 'unknown',
                    ];
                    $candidateBytes = 1024 + (6 * strlen($path)) + (6 * strlen((string) $name));
                    if (($outputBytes + $candidateBytes) > self::MAX_OUTPUT_BYTES) {
                        $reasons['output-byte-limit'] = true;
                        break 3;
                    }
                    $outputBytes += $candidateBytes;
                    $advisories[] = $advisory;
                    $selection['advisoryCandidates']++;
                }
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'translation-replacement-name-advisories',
                'evidence' => 'selected-php-source',
                'semantics' => 'optional-literal-replacement-key-naming-advisory',
                'policy' => 'ascii-colon-word',
                'acceptedPattern' => '^[A-Za-z0-9_]+$',
                'enabledBy' => 'explicit-source-export',
                'exhaustive' => false,
                'runtimeValidityClaimed' => false,
                'callIdentityValidated' => false,
                'effectiveTranslationValidated' => false,
                'replacementValuesExported' => false,
                'translationTextExported' => false,
            ],
            'selection' => $selection,
            'advisories' => $advisories,
            'uncertainties' => $uncertainties,
            'errors' => $errors,
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

        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php'
            ? null
            : 'unsupported-source-format';
    }

    /**
     * @return array{replacementIndex: int, syntax: string}|null
     */
    private static function replacementCall(Node\Expr\FuncCall|Node\Expr\StaticCall $call): ?array
    {
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            $function = strtolower($call->name->toString());
            if (in_array($function, ['__', 'trans'], true)) {
                return ['replacementIndex' => 1, 'syntax' => 'helper:'.$function];
            }
            if ($function === 'trans_choice') {
                return ['replacementIndex' => 2, 'syntax' => 'helper:trans_choice'];
            }

            return null;
        }
        if (
            ! $call instanceof Node\Expr\StaticCall
            || ! $call->class instanceof Node\Name\FullyQualified
            || strtolower($call->class->toString()) !== 'illuminate\\support\\facades\\lang'
            || ! $call->name instanceof Node\Identifier
        ) {
            return null;
        }

        $method = strtolower($call->name->toString());

        return match ($method) {
            'get', 'string' => ['replacementIndex' => 1, 'syntax' => 'facade:Lang::'.$method],
            'choice' => ['replacementIndex' => 2, 'syntax' => 'facade:Lang::choice'],
            default => null,
        };
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     */
    private static function argument(array $arguments, int $position): ?Node\Arg
    {
        foreach ($arguments as $argument) {
            if (
                $argument instanceof Node\Arg
                && $argument->name instanceof Node\Identifier
                && $argument->name->toString() === 'replace'
                && ! $argument->unpack
                && ! $argument->byRef
            ) {
                return $argument;
            }
        }
        foreach (array_slice($arguments, 0, $position + 1) as $argument) {
            if ($argument instanceof Node\Arg && $argument->name !== null) {
                return null;
            }
        }
        $argument = $arguments[$position] ?? null;

        return $argument instanceof Node\Arg && ! $argument->unpack && ! $argument->byRef
            ? $argument
            : null;
    }

    private static function literalKey(Node\Expr $node): string|int|null
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\UnaryMinus && $node->expr instanceof Node\Scalar\Int_) {
            return -$node->expr->value;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     */
    private static function appendUncertainty(
        array &$uncertainties,
        array &$reasons,
        int &$outputBytes,
        string $file,
        string $hash,
        Node $node,
        string $code,
    ): bool {
        if (count($uncertainties) >= self::MAX_UNCERTAINTIES) {
            $reasons['uncertainty-limit'] = true;

            return false;
        }
        $uncertaintyBytes = 768 + (6 * strlen($file));
        if (($outputBytes + $uncertaintyBytes) > self::MAX_OUTPUT_BYTES) {
            $reasons['output-byte-limit'] = true;

            return false;
        }
        $outputBytes += $uncertaintyBytes;
        $uncertainties[] = [
            'file' => $file,
            'code' => $code,
            'start' => $node->getStartFilePos(),
            'end' => $node->getEndFilePos() + 1,
            'line' => $node->getStartLine(),
            'contentHash' => $hash,
            'message' => match ($code) {
                'non-literal-replacement-array' => 'Replacement dictionary is not a direct literal array.',
                'unpacked-replacement-entries' => 'Unpacked replacement entries make selected literal keys uncertain.',
                'implicit-numeric-replacement-key'
                    => 'Implicit numeric replacement key is valid but has no literal key token.',
                default => 'Replacement dictionary key is not a supported literal string or integer.',
            },
        ];

        return true;
    }

    /** @return array{file: string, code: string, message: string} */
    private static function error(string $file, string $code): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'message' => match ($code) {
                'invalid-source-path' => 'Source must be a project-relative PHP file within the project root.',
                'unsupported-source-format' => 'Translation replacement call sources must use the PHP extension.',
                'parse-failure' => 'Unable to parse PHP translation call source.',
                'source-byte-limit' => 'PHP source exceeds the bounded read budget.',
                default => 'Unable to read PHP translation call source.',
            },
        ];
    }
}
