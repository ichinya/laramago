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
 * Top-level/namespace Route::verb(literal URI, action)->name(literal string)
 * expression statements and bounded literal Route::name(...)->group(closure)
 * nesting are supported. Imports must resolve to Illuminate\Support\Facades\Route.
 * Conditions, functions, handler bodies, and unsupported fluent chains are not traversed.
 */
final class RouteMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_DECLARATIONS = 10000;
    private const MAX_GROUP_DEPTH = 32;
    private const MAX_COMPOSED_NAME_BYTES = 4096;

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
            foreach (self::names($nodes, reasons: $reasons) as $candidate) {
                if (count($declarations) >= self::MAX_DECLARATIONS) {
                    $truncated = true;
                    $reasons['declaration-limit'] = true;
                    break 2;
                }
                $name = $candidate['leaf'];
                $declaration = [
                    'name' => $candidate['name'],
                    'file' => $path,
                    'start' => $name->getStartFilePos(),
                    'end' => $name->getEndFilePos() + 1,
                    'line' => $name->getStartLine(),
                    'contentHash' => $hash,
                    'confidence' => 'known-positive',
                ];
                if ($candidate['prefixes'] !== []) {
                    $declaration['rawName'] = $name->value;
                    $declaration['nameProvenance'] = [
                        'kind' => 'literal-concatenation',
                        'tokens' => [
                            ...array_map(
                                static fn (Node\Scalar\String_ $prefix): array => self::token($prefix, 'group-prefix'),
                                $candidate['prefixes'],
                            ),
                            self::token($name, 'route-name'),
                        ],
                    ];
                }
                $declarations[] = $declaration;
            }
            $truncated = $truncated || $reasons !== [];
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
     * @param list<Node\Scalar\String_> $prefixes
     * @param array<string, true> $reasons
     * @return \Generator<int, array{name: string, leaf: Node\Scalar\String_, prefixes: list<Node\Scalar\String_>}>
     */
    private static function names(
        array $nodes,
        string $prefix = '',
        array $prefixes = [],
        int $depth = 0,
        array &$reasons = [],
    ): \Generator {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::names($node->stmts, $prefix, $prefixes, $depth, $reasons);
                continue;
            }
            if (! $node instanceof Node\Stmt\Expression) {
                continue;
            }
            $group = self::literalGroup($node->expr);
            if ($group !== null) {
                [$groupPrefix, $callback] = $group;
                if ($depth >= self::MAX_GROUP_DEPTH) {
                    $reasons['group-depth-limit'] = true;
                    continue;
                }
                $composedPrefix = $prefix.$groupPrefix->value;
                if (strlen($composedPrefix) > self::MAX_COMPOSED_NAME_BYTES) {
                    $reasons['composed-name-byte-limit'] = true;
                    continue;
                }
                yield from self::names(
                    $callback->stmts,
                    $composedPrefix,
                    [...$prefixes, $groupPrefix],
                    $depth + 1,
                    $reasons,
                );
                continue;
            }
            if (! $node->expr instanceof Node\Expr\MethodCall) {
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
            $leaf = $call->args[0]->value;
            $composed = $prefix.$leaf->value;
            if ($prefixes !== [] && strlen($composed) > self::MAX_COMPOSED_NAME_BYTES) {
                $reasons['composed-name-byte-limit'] = true;
                continue;
            }
            yield ['name' => $composed, 'leaf' => $leaf, 'prefixes' => $prefixes];
        }
    }

    /** @return array{Node\Scalar\String_, Node\Expr\Closure}|null */
    private static function literalGroup(Node\Expr $expression): ?array
    {
        $prefix = null;
        $callback = null;
        if (
            $expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'group'
            && self::positional($expression->args, 1)
            && $expression->args[0]->value instanceof Node\Expr\Closure
        ) {
            $base = $expression->var;
            if (
                $base instanceof Node\Expr\StaticCall
                && self::routeCall($base, 'name')
                && self::positional($base->args, 1)
                && $base->args[0]->value instanceof Node\Scalar\String_
            ) {
                $prefix = $base->args[0]->value;
                $callback = $expression->args[0]->value;
            }
        } elseif (
            $expression instanceof Node\Expr\StaticCall
            && self::routeCall($expression, 'group')
            && self::positional($expression->args, 2)
            && $expression->args[0]->value instanceof Node\Expr\Array_
            && count($expression->args[0]->value->items) === 1
            && $expression->args[1]->value instanceof Node\Expr\Closure
        ) {
            $attribute = $expression->args[0]->value->items[0];
            if (
                ! $attribute->unpack
                && ! $attribute->byRef
                && $attribute->key instanceof Node\Scalar\String_
                && $attribute->key->value === 'as'
                && $attribute->value instanceof Node\Scalar\String_
            ) {
                $prefix = $attribute->value;
                $callback = $expression->args[1]->value;
            }
        }
        if ($prefix === null || $callback === null || $callback->params !== [] || $callback->uses !== []) {
            return null;
        }

        return [$prefix, $callback];
    }

    private static function routeCall(Node\Expr\StaticCall $call, string $method): bool
    {
        return (
            $call->class instanceof Node\Name\FullyQualified
            && strtolower($call->class->toString()) === 'illuminate\support\facades\route'
            && $call->name instanceof Node\Identifier
            && (
                $method === 'name'
                    ? $call->name->toString() === 'name'
                    : strtolower($call->name->toString()) === $method
            )
        );
    }

    /** @return array{role: string, value: string, start: int, end: int, line: int} */
    private static function token(Node\Scalar\String_ $literal, string $role): array
    {
        return [
            'role' => $role,
            'value' => $literal->value,
            'start' => $literal->getStartFilePos(),
            'end' => $literal->getEndFilePos() + 1,
            'line' => $literal->getStartLine(),
        ];
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
