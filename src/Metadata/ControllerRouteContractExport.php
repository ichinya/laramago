<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Source-only links between literal controller route actions and their declared
 * public method signatures. The result is review metadata, not dispatch
 * validation: Laravel may replace routes, mutate parameters, run binders or
 * middleware, and resolve class dependencies from its runtime container.
 */
final class ControllerRouteContractExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_CONTRACTS = 10000;
    private const MAX_PARAMETERS = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP route and controller sources.
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
                'Controller route sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        /** @var list<array{file: string, contents: string, hash: string, nodes: array<array-key, Node>}> $sources */
        $sources = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSourceName($file)) {
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
            if (! $contained || ! str_ends_with(strtolower($path), '.php')) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
            if ($remaining <= 0) {
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                break;
            }
            $contents = @file_get_contents($path, false, null, 0, min(self::MAX_FILE_BYTES, $remaining) + 1);
            if ($contents === false) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }

            $size = strlen($contents);
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                break;
            }
            if ($size > self::MAX_FILE_BYTES) {
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

            $sources[] = [
                'file' => $path,
                'contents' => $contents,
                'hash' => hash('sha256', $contents),
                'nodes' => $nodes,
            ];
        }

        /**
         * @var array<string, array<string, list<array{
         *     class: string,
         *     method: Node\Stmt\ClassMethod,
         *     source: array{file: string, contents: string, hash: string, nodes: array<array-key, Node>}
         * }>>> $methods
         */
        $methods = [];
        $classDeclarations = [];
        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                if (! $statement instanceof Node\Stmt\Class_ || $statement->namespacedName === null) {
                    continue;
                }
                $class = $statement->namespacedName->toString();
                $classKey = strtolower($class);
                $classDeclarations[$classKey] = ($classDeclarations[$classKey] ?? 0) + 1;
                foreach ($statement->getMethods() as $method) {
                    if (! $method->isPublic()) {
                        continue;
                    }
                    $methods[strtolower($class)][strtolower($method->name->toString())][] = [
                        'class' => $class,
                        'method' => $method,
                        'source' => $source,
                    ];
                }
            }
        }

        $contracts = [];
        $actionCandidates = 0;
        $unmatchedActionCandidates = 0;
        $ambiguousActionCandidates = 0;
        $parameterCount = 0;

        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                if (! $statement instanceof Node\Stmt\Expression) {
                    continue;
                }
                $route = self::route($statement->expr, $source);
                if ($route === null) {
                    continue;
                }
                $actionCandidates++;
                if (($classDeclarations[strtolower($route['action']['class'])] ?? 0) > 1) {
                    $ambiguousActionCandidates++;
                    continue;
                }
                $definitions = $methods[strtolower($route['action']['class'])][strtolower($route['action']['method'])]
                ?? [];
                if (count($definitions) !== 1) {
                    count($definitions) === 0 ? $unmatchedActionCandidates++ : $ambiguousActionCandidates++;
                    continue;
                }

                $definition = $definitions[0];
                $method = $definition['method'];
                $nextParameterCount = $parameterCount + count($method->params);
                if (count($contracts) >= self::MAX_CONTRACTS) {
                    $reasons['contract-limit'] = true;
                    break 2;
                }
                if ($nextParameterCount > self::MAX_PARAMETERS) {
                    $reasons['parameter-limit'] = true;
                    break 2;
                }
                $parameterCount = $nextParameterCount;

                $placeholders = array_merge($route['domain']['placeholders'], $route['uri']['placeholders']);
                $parameters = [];
                $position = 0;
                foreach ($method->params as $parameter) {
                    $parameters[] = self::parameter($parameter, $position, $definition['source'], $placeholders);
                    $position++;
                }

                $contracts[] = [
                    'confidence' => 'source-only-candidate',
                    'runtimeDispatchValidated' => false,
                    'route' => $route['route'],
                    'uri' => $route['uri'],
                    'domain' => $route['domain'],
                    'action' => $route['action'],
                    'controller' => [
                        'class' => $definition['class'],
                        'method' => $method->name->toString(),
                        'file' => $definition['source']['file'],
                        'start' => $method->getStartFilePos(),
                        'end' => $method->getEndFilePos() + 1,
                        'line' => $method->getStartLine(),
                        'contentHash' => $definition['source']['hash'],
                        'parameters' => $parameters,
                    ],
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'controller-route-contract-candidates',
                'evidence' => 'source-only',
                'semantics' => 'literal-action-to-declared-signature',
                'exhaustive' => false,
                'runtimeDispatchValidated' => false,
            ],
            'contracts' => $contracts,
            'selection' => [
                'actionCandidates' => $actionCandidates,
                'matchedContracts' => count($contracts),
                'unmatchedActionCandidates' => $unmatchedActionCandidates,
                'ambiguousActionCandidates' => $ambiguousActionCandidates,
            ],
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function validSourceName(mixed $file): bool
    {
        return (
            is_string($file)
            && $file !== ''
            && ! str_contains($file, "\0")
            && ! str_contains($file, ':')
            && ! preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            && str_ends_with(strtolower($file), '.php')
        );
    }

    /**
     * @param array<array-key, Node> $nodes
     * @return \Generator<int, Node\Stmt>
     */
    private static function topLevel(array $nodes): \Generator
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::topLevel($node->stmts);
            } elseif ($node instanceof Node\Stmt) {
                yield $node;
            }
        }
    }

    /**
     * @param array{file: string, contents: string, hash: string, nodes: array<array-key, Node>} $source
     * @return array{
     *     route: array<string, mixed>,
     *     uri: array{value: string, start: int, end: int, line: int, placeholders: list<array{name: string, optional: bool, source: string}>},
     *     domain: array{
     *         state: string,
     *         placeholders: list<array{name: string, optional: bool, source: string}>,
     *         value?: string,
     *         start?: int,
     *         end?: int,
     *         line?: int
     *     },
     *     action: array{class: string, method: string, kind: string, file: string, start: int, end: int, line: int}
     * }|null
     */
    private static function route(Node\Expr $expression, array $source): ?array
    {
        $domain = ['state' => 'not-observed', 'placeholders' => []];
        $base = $expression;
        while ($base instanceof Node\Expr\MethodCall) {
            if (
                $domain['state'] === 'not-observed'
                && $base->name instanceof Node\Identifier
                && strtolower($base->name->toString()) === 'domain'
            ) {
                $argument = $base->args[0] ?? null;
                if (
                    self::positional($base->args, 1)
                    && $argument instanceof Node\Arg
                    && $argument->value instanceof Node\Scalar\String_
                ) {
                    $literal = $argument->value;
                    $domain = [
                        'state' => 'literal-candidate',
                        'value' => $literal->value,
                        'start' => $literal->getStartFilePos(),
                        'end' => $literal->getEndFilePos() + 1,
                        'line' => $literal->getStartLine(),
                        'placeholders' => self::placeholders($literal->value, 'domain'),
                    ];
                } else {
                    $domain = ['state' => 'dynamic', 'placeholders' => []];
                }
            }
            $base = $base->var;
        }

        $uriArgument = $base instanceof Node\Expr\StaticCall ? $base->args[0] ?? null : null;
        $actionArgument = $base instanceof Node\Expr\StaticCall ? $base->args[1] ?? null : null;
        if (
            ! $base instanceof Node\Expr\StaticCall
            || ! $base->class instanceof Node\Name\FullyQualified
            || strcasecmp($base->class->toString(), 'Illuminate\Support\Facades\Route') !== 0
            || ! $base->name instanceof Node\Identifier
            || ! in_array(
                strtolower($base->name->toString()),
                ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'],
                true,
            )
            || ! self::positional($base->args, 2)
            || ! $uriArgument instanceof Node\Arg
            || ! $uriArgument->value instanceof Node\Scalar\String_
            || ! $actionArgument instanceof Node\Arg
        ) {
            return null;
        }

        $action = self::action($actionArgument->value, $source);
        if ($action === null) {
            return null;
        }
        $uri = $uriArgument->value;

        return [
            'route' => [
                'method' => strtolower($base->name->toString()),
                'file' => $source['file'],
                'start' => $base->getStartFilePos(),
                'end' => $base->getEndFilePos() + 1,
                'line' => $base->getStartLine(),
                'contentHash' => $source['hash'],
            ],
            'uri' => [
                'value' => $uri->value,
                'start' => $uri->getStartFilePos(),
                'end' => $uri->getEndFilePos() + 1,
                'line' => $uri->getStartLine(),
                'placeholders' => self::placeholders($uri->value, 'uri'),
            ],
            'domain' => $domain,
            'action' => $action,
        ];
    }

    /**
     * @param array{file: string, contents: string, hash: string, nodes: array<array-key, Node>} $source
     * @return array{class: string, method: string, kind: string, file: string, start: int, end: int, line: int}|null
     */
    private static function action(Node\Expr $expression, array $source): ?array
    {
        $class = null;
        $method = null;
        $kind = null;

        $first = $expression instanceof Node\Expr\Array_ ? $expression->items[0] ?? null : null;
        $second = $expression instanceof Node\Expr\Array_ ? $expression->items[1] ?? null : null;
        if (
            $expression instanceof Node\Expr\Array_
            && count($expression->items) === 2
            && $first instanceof Node\ArrayItem
            && $second instanceof Node\ArrayItem
            && $first->key === null
            && $second->key === null
            && ! $first->unpack
            && ! $second->unpack
            && $first->value instanceof Node\Expr\ClassConstFetch
            && $first->value->class instanceof Node\Name\FullyQualified
            && $first->value->name instanceof Node\Identifier
            && strtolower($first->value->name->toString()) === 'class'
            && $second->value instanceof Node\Scalar\String_
            && $second->value->value !== ''
        ) {
            $class = $first->value->class->toString();
            $method = $second->value->value;
            $kind = 'class-method-array';
        } elseif (
            $expression instanceof Node\Expr\ClassConstFetch
            && $expression->class instanceof Node\Name\FullyQualified
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'class'
        ) {
            $class = $expression->class->toString();
            $method = '__invoke';
            $kind = 'invokable-class';
        } elseif ($expression instanceof Node\Scalar\String_ && str_starts_with($expression->value, '\\')) {
            $parts = explode('@', substr($expression->value, 1));
            if (count($parts) === 1 && $parts[0] !== '') {
                [$class, $method, $kind] = [$parts[0], '__invoke', 'absolute-invokable-string'];
            } elseif (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                [$class, $method, $kind] = [$parts[0], $parts[1], 'absolute-class-method-string'];
            }
        }

        if ($class === null || $method === null || $kind === null) {
            return null;
        }

        return [
            'class' => $class,
            'method' => $method,
            'kind' => $kind,
            'file' => $source['file'],
            'start' => $expression->getStartFilePos(),
            'end' => $expression->getEndFilePos() + 1,
            'line' => $expression->getStartLine(),
        ];
    }

    /**
     * @param array{file: string, contents: string, hash: string, nodes: array<array-key, Node>} $source
     * @param list<array{name: string, optional: bool, source: string}> $placeholders
     * @return array<string, mixed>
     */
    private static function parameter(Node\Param $parameter, int $position, array $source, array $placeholders): array
    {
        $variable = $parameter->var;
        $name = $variable instanceof Node\Expr\Variable && is_string($variable->name) ? $variable->name : '';
        $matching = [];
        $snake = self::snake($name);
        foreach ($placeholders as $placeholder) {
            if ($placeholder['name'] === $name || $placeholder['name'] === $snake) {
                $matching[] = $placeholder;
            }
        }

        $injectionClass = self::injectionClass($parameter->type);
        $nativeType = null;
        $nativeTypeSpan = null;
        if ($parameter->type !== null) {
            $nativeType = substr(
                $source['contents'],
                $parameter->type->getStartFilePos(),
                $parameter->type->getEndFilePos() - $parameter->type->getStartFilePos() + 1,
            );
            $nativeTypeSpan = [
                'start' => $parameter->type->getStartFilePos(),
                'end' => $parameter->type->getEndFilePos() + 1,
                'line' => $parameter->type->getStartLine(),
            ];
        }

        return [
            'position' => $position,
            'name' => $name,
            'start' => $variable->getStartFilePos(),
            'end' => $variable->getEndFilePos() + 1,
            'line' => $variable->getStartLine(),
            'nativeType' => $nativeType,
            'nativeTypeSpan' => $nativeTypeSpan,
            'namedClassDependencyCandidate' => $injectionClass,
            'matchingRoutePlaceholders' => $matching,
            'hasDefault' => $parameter->default !== null,
            'variadic' => $parameter->variadic,
            'byReference' => $parameter->byRef,
        ];
    }

    /** @return list<array{name: string, optional: bool, source: string}> */
    private static function placeholders(string $value, string $source): array
    {
        $matches = [];
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}/', $value, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => [
                'name' => $match[1],
                'optional' => ($match[2] ?? '') === '?',
                'source' => $source,
            ],
            $matches,
        );
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private static function injectionClass(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        return $type instanceof Node\Name ? $type->toString() : null;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private static function positional(array $arguments, int $count): bool
    {
        if (count($arguments) !== $count) {
            return false;
        }
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->unpack) {
                return false;
            }
        }

        return true;
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return ['code' => $code, 'file' => $file, 'message' => 'Selected source could not be exported safely.'];
    }
}
