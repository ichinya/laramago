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
 * Literal pagination view candidates from explicitly selected PHP sources.
 *
 * Instance receiver identity and the mutable static view-factory resolver are
 * deliberately unresolved. The result supports an optional source policy; it
 * does not prove that Laravel will look up or render a named view.
 */
final class PaginationViewReferenceExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 20000;
    private const MAX_UNCERTAINTIES = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP sources.
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
                'Pagination view sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $references = [];
        $uncertainties = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
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
                    $code = 'invalid-source';
                } elseif (! str_ends_with(strtolower($path), '.php')) {
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
            /** @var list<Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall> $calls */
            $calls = $finder->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\NullsafeMethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            );
            foreach ($calls as $call) {
                $kind = self::callKind($call);
                if ($kind === null) {
                    continue;
                }
                $argument = self::viewArgument($call->args);
                if ($argument?->value instanceof Node\Scalar\String_) {
                    $literal = $argument->value;
                    if (! $kind['defaultDeclaration'] && ! self::truthyString($literal->value)) {
                        if (! self::addUncertainty(
                            $uncertainties,
                            $reasons,
                            $path,
                            $hash,
                            $call,
                            'falsey-view-uses-runtime-default',
                            'A falsey pagination view argument selects the mutable runtime default.',
                        )) {
                            break 2;
                        }
                        continue;
                    }
                    if (count($references) >= self::MAX_REFERENCES) {
                        $reasons['reference-limit'] = true;
                        break 2;
                    }
                    $references[] = [
                        'name' => $literal->value,
                        'file' => $path,
                        'start' => $literal->getStartFilePos(),
                        'end' => $literal->getEndFilePos() + 1,
                        'line' => $literal->getStartLine(),
                        'contentHash' => $hash,
                        'syntax' => $kind['syntax'],
                        'selection' => $kind['defaultDeclaration']
                            ? 'mutable-default-declaration'
                            : 'truthy-explicit-argument',
                        'confidence' => 'declaration-policy-candidate',
                        'paginatorReceiver' => $kind['defaultDeclaration']
                            ? 'exact-static-paginator'
                            : 'unresolved',
                        'viewFactoryResolver' => 'unresolved',
                        'runtimeLookup' => 'unresolved',
                    ];
                    continue;
                }

                $falsey = $argument instanceof Node\Arg && self::staticallyFalsey($argument->value);
                $code = $falsey && ! $kind['defaultDeclaration']
                    ? 'falsey-view-uses-runtime-default'
                    : (
                        $argument === null
                            ? (
                                ! $kind['defaultDeclaration'] && $call->args === []
                                    ? 'implicit-runtime-default'
                                    : 'unresolved-view-argument'
                            )
                            : 'non-literal-view-argument'
                    );
                $message = match ($code) {
                    'falsey-view-uses-runtime-default'
                        => 'A falsey pagination view argument selects the mutable runtime default.',
                    'implicit-runtime-default'
                        => 'The pagination call has no directly selected view argument and uses mutable runtime state.',
                    default => 'The pagination view argument is not a directly selected string literal.',
                };
                if (! self::addUncertainty($uncertainties, $reasons, $path, $hash, $call, $code, $message)) {
                    break 2;
                }
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'pagination-view-references',
                'evidence' => 'selected-php-source',
                'semantics' => 'optional-declaration-policy-candidates',
                'exhaustive' => false,
                'paginatorReceiverValidated' => false,
                'viewFactoryResolverValidated' => false,
                'runtimeLookupValidated' => false,
            ],
            'references' => $references,
            'uncertainties' => $uncertainties,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * @return array{syntax: string, defaultDeclaration: bool}|null
     */
    private static function callKind(
        Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $call,
    ): ?array {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }
        $method = strtolower($call->name->toString());
        if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) {
            return match ($method) {
                'links' => ['syntax' => 'pagination-links-argument', 'defaultDeclaration' => false],
                'render' => ['syntax' => 'pagination-render-argument', 'defaultDeclaration' => false],
                default => null,
            };
        }
        if (
            ! ($call->class instanceof Node\Name\FullyQualified
            && strtolower($call->class->toString()) === 'illuminate\\pagination\\paginator')
        ) {
            return null;
        }

        return match ($method) {
            'defaultview' => ['syntax' => 'paginator-default-view-declaration', 'defaultDeclaration' => true],
            'defaultsimpleview' => [
                'syntax' => 'paginator-default-simple-view-declaration',
                'defaultDeclaration' => true,
            ],
            default => null,
        };
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     */
    private static function viewArgument(array $arguments): ?Node\Arg
    {
        foreach ($arguments as $argument) {
            if (
                $argument instanceof Node\Arg
                && $argument->name instanceof Node\Identifier
                && $argument->name->toString() === 'view'
                && ! $argument->unpack
                && ! $argument->byRef
            ) {
                return $argument;
            }
        }
        if (
            isset($arguments[0])
            && $arguments[0] instanceof Node\Arg
            && $arguments[0]->name === null
            && ! $arguments[0]->unpack
            && ! $arguments[0]->byRef
        ) {
            return $arguments[0];
        }

        return null;
    }

    private static function truthyString(string $value): bool
    {
        return $value !== '' && $value !== '0';
    }

    private static function staticallyFalsey(Node\Expr $value): bool
    {
        return (
            $value instanceof Node\Expr\ConstFetch
            && in_array(strtolower($value->name->toString()), ['false', 'null'], true)
            || $value instanceof Node\Scalar\Int_
            && $value->value === 0
            || $value instanceof Node\Scalar\Float_
            && $value->value === 0.0
            || $value instanceof Node\Expr\Array_
            && $value->items === []
        );
    }

    /**
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     */
    private static function addUncertainty(
        array &$uncertainties,
        array &$reasons,
        string $path,
        string $hash,
        Node $call,
        string $code,
        string $message,
    ): bool {
        if (count($uncertainties) >= self::MAX_UNCERTAINTIES) {
            $reasons['uncertainty-limit'] = true;

            return false;
        }
        $uncertainties[] = [
            'file' => $path,
            'code' => $code,
            'start' => $call->getStartFilePos(),
            'end' => $call->getEndFilePos() + 1,
            'line' => $call->getStartLine(),
            'contentHash' => $hash,
            'message' => $message,
        ];

        return true;
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
            return 'invalid-source';
        }

        return str_ends_with(strtolower($file), '.php') ? null : 'unsupported-source-format';
    }

    /** @return array{file: string, code: string, message: string} */
    private static function error(string $file, string $code): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'message' => match ($code) {
                'invalid-source' => 'Source must be a project-relative PHP file contained in the project.',
                'unsupported-source-format' => 'Pagination view source must use the PHP extension.',
                'parse-failure' => 'Unable to parse static pagination view source.',
                'source-byte-limit' => 'Static pagination view source exceeds the bounded read budget.',
                default => 'Unable to read static pagination view source.',
            },
        ];
    }
}
