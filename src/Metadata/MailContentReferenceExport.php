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
 * Literal constructor arguments for Illuminate Mailables Content.
 *
 * These are editor reference candidates only. Content is mutable, hydration is
 * conditional, and Mailable rendering can retain or replace every declaration.
 */
final class MailContentReferenceExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 20000;
    private const CONTENT_CLASS = 'Illuminate\\Mail\\Mailables\\Content';

    /** @var array<int, string> */
    private const POSITIONAL_ROLES = [
        0 => 'view',
        1 => 'html',
        2 => 'text',
        3 => 'markdown',
    ];

    /** @var array<string, string> */
    private const REFERENCE_KINDS = [
        'view' => 'html-view',
        'html' => 'html-view-alias',
        'text' => 'text-view',
        'markdown' => 'markdown-view',
    ];

    /**
     * @param array<array-key, string> $files Project-relative PHP source list.
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
                'Mail content sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $references = [];
        $errors = [];
        $reasons = [];
        $seen = [];
        $totalBytes = 0;
        $selection = [
            'filesRequested' => count($files),
            'filesRead' => 0,
            'constructorsScanned' => 0,
            'literalReferences' => 0,
            'falseyLiteralsSkipped' => 0,
            'dynamicArgumentsDeferred' => 0,
            'unpackedArgumentsDeferred' => 0,
            'unstablePositionalArgumentsDeferred' => 0,
            'rawHtmlArgumentsObserved' => 0,
        ];

        if (count($files) > self::MAX_FILES) {
            $reasons['file-limit'] = true;
        }

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSource($file)) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }

            clearstatcache(true);
            $path = realpath($prefix.$file);
            if ($path === false || ! is_file($path)) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $path = str_replace('\\', '/', $path);
            $contained = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($path), strtolower($prefix))
                : str_starts_with($path, $prefix);
            if (! $contained || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }

            $identity = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
            $contents = @file_get_contents($path, false, null, 0, min(self::MAX_FILE_BYTES, $remaining) + 1);
            if ($contents === false) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($size > self::MAX_FILE_BYTES || $totalBytes > self::MAX_TOTAL_BYTES) {
                $reason = $totalBytes > self::MAX_TOTAL_BYTES ? 'total-byte-limit' : 'file-byte-limit';
                $reasons[$reason] = true;
                $errors[] = self::error('source-limit', $file);
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    break;
                }
                continue;
            }

            try {
                $nodes = $parser->parse($contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (Error) {
                $errors[] = self::error('parse-failure', $file);
                continue;
            }

            $selection['filesRead']++;
            self::collect(
                $nodes,
                ['file' => $path, 'hash' => hash('sha256', $contents)],
                $references,
                $selection,
                $reasons,
            );
            if (isset($reasons['reference-limit'])) {
                break;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'mail-content-reference-candidates',
                'evidence' => 'source-only',
                'semantics' => 'editor-reference-candidates',
                'exhaustive' => false,
                'constructorClass' => self::CONTENT_CLASS,
                'constructorSignatureValidated' => false,
                'effectiveContentStateValidated' => false,
                'terminalRenderValidated' => false,
                'runtimeLookupRequirementValidated' => false,
                'missingViewDiagnosticEligible' => false,
            ],
            'selection' => $selection,
            'references' => $references,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function validSource(string $file): bool
    {
        return (
            $file !== ''
            && ! str_contains($file, "\0")
            && ! str_contains($file, ':')
            && ! preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php'
        );
    }

    /**
     * @param array<array-key, Node> $nodes
     * @param array{file: string, hash: string} $source
     * @param list<array<string, mixed>> $references
     * @param array<string, int> $selection
     * @param array<string, true> $reasons
     */
    private static function collect(
        array $nodes,
        array $source,
        array &$references,
        array &$selection,
        array &$reasons,
    ): void {
        $constructors = (new NodeFinder)->findInstanceOf($nodes, Node\Expr\New_::class);
        usort(
            $constructors,
            static fn (
                Node\Expr\New_ $left,
                Node\Expr\New_ $right,
            ): int => $left->getStartFilePos() <=> $right->getStartFilePos(),
        );
        foreach ($constructors as $constructor) {
            if (
                ! $constructor->class instanceof Node\Name\FullyQualified
                || strtolower($constructor->class->toString()) !== strtolower(self::CONTENT_CLASS)
            ) {
                continue;
            }
            $selection['constructorsScanned']++;
            $position = 0;
            $positionStable = true;
            foreach ($constructor->args as $argument) {
                if (! $argument instanceof Node\Arg) {
                    $positionStable = false;
                    continue;
                }

                $role = null;
                $argumentName = $argument->name?->toString();
                if ($argumentName !== null) {
                    if ($argumentName === 'htmlString') {
                        $selection['rawHtmlArgumentsObserved']++;
                    }
                    if (isset(self::REFERENCE_KINDS[$argumentName])) {
                        $role = $argumentName;
                    }
                } elseif ($argument->unpack) {
                    $positionStable = false;
                    $selection['unpackedArgumentsDeferred']++;
                } elseif ($positionStable) {
                    $role = self::POSITIONAL_ROLES[$position] ?? null;
                    if ($position === 5) {
                        $selection['rawHtmlArgumentsObserved']++;
                    }
                    $position++;
                } else {
                    $selection['unstablePositionalArgumentsDeferred']++;
                }

                if ($role === null) {
                    continue;
                }
                if (! $argument->value instanceof Node\Scalar\String_) {
                    $selection['dynamicArgumentsDeferred']++;
                    continue;
                }
                if ($argument->value->value === '' || $argument->value->value === '0') {
                    $selection['falseyLiteralsSkipped']++;
                    continue;
                }
                if (count($references) >= self::MAX_REFERENCES) {
                    $reasons['reference-limit'] = true;

                    return;
                }

                $literal = $argument->value;
                $references[] = [
                    'name' => $literal->value,
                    'referenceKind' => self::REFERENCE_KINDS[$role],
                    'constructorArgument' => [
                        'role' => $role,
                        'position' => $argumentName === null ? $position - 1 : null,
                        'name' => $argumentName,
                    ],
                    'file' => $source['file'],
                    'start' => $literal->getStartFilePos(),
                    'end' => $literal->getEndFilePos() + 1,
                    'line' => $literal->getStartLine(),
                    'contentHash' => $source['hash'],
                    'runtimeLookup' => 'unknown',
                    'confidence' => 'declaration-candidate',
                ];
                $selection['literalReferences']++;
            }
        }
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'parse-failure' => 'Unable to parse static mail content source.',
                'source-limit' => 'Static mail content source byte limit exceeded.',
                'invalid-source' => 'Mail content source must be a project-relative PHP file contained in the project.',
                default => 'Unable to read static mail content source.',
            },
        ];
    }
}
