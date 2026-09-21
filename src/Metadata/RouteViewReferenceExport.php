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
 * Literal view names declared by native Route facade view calls.
 *
 * These references support an optional declaration policy. They do not prove
 * that Laravel will look up the literal: route defaults, parameters, bindings,
 * middleware, or later mutations may replace it before dispatch.
 */
final class RouteViewReferenceExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 20000;
    private const MAX_UNCERTAINTIES = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP route sources.
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
            throw new \InvalidArgumentException('Route view sources must be a list of project-relative PHP files.');
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
            /** @var list<Node\Expr\StaticCall> $calls */
            $calls = $finder->findInstanceOf($nodes, Node\Expr\StaticCall::class);
            foreach ($calls as $call) {
                if (! self::nativeRouteViewCall($call)) {
                    continue;
                }
                $argument = self::viewArgument($call->args);
                if ($argument?->value instanceof Node\Scalar\String_) {
                    if (count($references) >= self::MAX_REFERENCES) {
                        $reasons['reference-limit'] = true;
                        break 2;
                    }
                    $literal = $argument->value;
                    $references[] = [
                        'name' => $literal->value,
                        'file' => $path,
                        'start' => $literal->getStartFilePos(),
                        'end' => $literal->getEndFilePos() + 1,
                        'line' => $literal->getStartLine(),
                        'contentHash' => $hash,
                        'syntax' => 'route-facade-view-argument',
                        'confidence' => 'declaration-policy-reference',
                        'runtimeLookup' => 'unresolved',
                    ];
                    continue;
                }
                if (count($uncertainties) >= self::MAX_UNCERTAINTIES) {
                    $reasons['uncertainty-limit'] = true;
                    break 2;
                }
                $uncertainties[] = [
                    'file' => $path,
                    'code' => 'non-literal-view-argument',
                    'start' => $call->getStartFilePos(),
                    'end' => $call->getEndFilePos() + 1,
                    'line' => $call->getStartLine(),
                    'contentHash' => $hash,
                    'message' => 'Native Route::view call has no directly selected literal view argument.',
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'route-view-references',
                'evidence' => 'selected-php-source',
                'semantics' => 'optional-declaration-policy',
                'exhaustive' => false,
                'runtimeLookupValidated' => false,
            ],
            'references' => $references,
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
            return 'invalid-source';
        }

        return str_ends_with(strtolower($file), '.php') ? null : 'unsupported-source-format';
    }

    private static function nativeRouteViewCall(Node\Expr\StaticCall $call): bool
    {
        return (
            $call->class instanceof Node\Name\FullyQualified
            && strtolower($call->class->toString()) === 'illuminate\\support\\facades\\route'
            && $call->name instanceof Node\Identifier
            && strtolower($call->name->toString()) === 'view'
        );
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
            isset($arguments[0], $arguments[1])
            && $arguments[0] instanceof Node\Arg
            && $arguments[1] instanceof Node\Arg
            && $arguments[0]->name === null
            && $arguments[1]->name === null
            && ! $arguments[0]->unpack
            && ! $arguments[1]->unpack
            && ! $arguments[1]->byRef
        ) {
            return $arguments[1];
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
                'invalid-source' => 'Source must be a project-relative PHP file contained in the project.',
                'unsupported-source-format' => 'Route view source must use the PHP extension.',
                'parse-failure' => 'Unable to parse static route view source.',
                'source-byte-limit' => 'Static route view source exceeds the bounded read budget.',
                default => 'Unable to read static route view source.',
            },
        ];
    }
}
