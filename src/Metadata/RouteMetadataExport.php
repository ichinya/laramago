<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Literal route declaration candidates, never a catalog of effective runtime names.
 * Only top-level/namespace Route::verb(literal URI, action)->name(literal string)
 * expression statements are supported, with positional arguments and exactly one
 * method call. Imports must resolve to Illuminate\Support\Facades\Route. Groups,
 * conditionals, functions, handler bodies, and other fluent chains are not traversed.
 */
final class RouteMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_DECLARATIONS = 10000;

    /**
     * @param array<array-key, string> $files Project-relative PHP source list.
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        clearstatcache(true);
        $directory = realpath($root);
        if ($directory === false || ! is_dir($directory)) {
            throw new \InvalidArgumentException('Project root must be an existing directory.');
        }
        if (! array_is_list($files)) {
            throw new \InvalidArgumentException('Route sources must be a list of project-relative PHP files.');
        }
        $projectRoot = str_replace('\\', '/', $directory);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $declarations = [];
        $errors = [];
        $truncated = count($files) > self::MAX_FILES;
        $reasons = $truncated ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (
                $file === ''
                || str_contains($file, "\0")
                || str_contains($file, ':')
                || preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
                || ! str_ends_with($file, '.php')
            ) {
                $errors[] = self::error('invalid-source', null);
                continue;
            }
            clearstatcache(true);
            $path = realpath($directory.$file);
            if ($path === false || ! is_file($path)) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $path = str_replace('\\', '/', $path);
            $contained = PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($path), strtolower($directory))
                : str_starts_with($path, $directory);
            if (! $contained) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $contents = @file_get_contents(
                $path,
                false,
                null,
                0,
                min(self::MAX_FILE_BYTES, self::MAX_TOTAL_BYTES - $totalBytes) + 1,
            );
            if ($contents === false) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $truncated = true;
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                break;
            }
            if ($size > self::MAX_FILE_BYTES) {
                $truncated = true;
                $reasons['file-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                continue;
            }
            try {
                $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse($contents) ?? []);
            } catch (Error) {
                $errors[] = self::error('parse-failure', $file);
                continue;
            }
            $hash = hash('sha256', $contents);
            foreach (self::names($nodes) as $name) {
                if (count($declarations) >= self::MAX_DECLARATIONS) {
                    $truncated = true;
                    $reasons['declaration-limit'] = true;
                    break 2;
                }
                $declarations[] = [
                    'name' => $name->value,
                    'file' => $path,
                    'start' => $name->getStartFilePos(),
                    'end' => $name->getEndFilePos() + 1,
                    'line' => $name->getStartLine(),
                    'contentHash' => $hash,
                    'confidence' => 'known-positive',
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => ['kind' => 'routes', 'evidence' => 'source-only'],
            'declarations' => $declarations,
            'errors' => $errors,
            'truncated' => $truncated,
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * @param array<array-key, Node> $nodes
     * @return \Generator<int, Node\Scalar\String_>
     */
    private static function names(array $nodes): \Generator
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::names($node->stmts);
                continue;
            }
            if (! $node instanceof Node\Stmt\Expression || ! $node->expr instanceof Node\Expr\MethodCall) {
                continue;
            }
            $call = $node->expr;
            $base = $call->var;
            if (
                ! $call->name instanceof Node\Identifier
                || strtolower($call->name->toString()) !== 'name'
                || ! self::positional($call->args, 1)
                || ! $call->args[0]->value instanceof Node\Scalar\String_
                || ! $base instanceof Node\Expr\StaticCall
                || ! $base->class instanceof Node\Name\FullyQualified
                || strtolower($base->class->toString()) !== 'illuminate\\support\\facades\\route'
                || ! $base->name instanceof Node\Identifier
                || ! in_array(
                    strtolower($base->name->toString()),
                    ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'],
                    true,
                )
                || ! self::positional($base->args, 2)
                || ! $base->args[0]->value instanceof Node\Scalar\String_
            ) {
                continue;
            }
            yield $call->args[0]->value;
        }
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @phpstan-assert-if-true list<Node\Arg> $args
     */
    private static function positional(array $args, int $count): bool
    {
        if (count($args) !== $count || ! array_is_list($args)) {
            return false;
        }
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'parse-failure' => 'Unable to parse static route source.',
                'source-limit' => 'Static route source byte limit exceeded.',
                'invalid-source' => 'Route source must be a project-relative PHP file contained in the project.',
                default => 'Unable to read static route source.',
            },
        ];
    }
}
