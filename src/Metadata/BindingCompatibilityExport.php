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
 * Source-only declaration compatibility candidates for literal container
 * registrations. This export is an opt-in review aid, not a Laravel runtime
 * error: container registrations may intentionally use unrelated service IDs,
 * custom factories, later rebinding, contextual bindings or extenders.
 */
final class BindingCompatibilityExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_CANDIDATES = 10000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP declaration and registration sources.
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
            throw new \InvalidArgumentException('Binding sources must be a list of project-relative PHP files.');
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        /** @var list<array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}> $sources */
        $sources = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSourceName($file)) {
                $errors[] = self::error('invalid-source', $file);
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
            $identity = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

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
                'contentHash' => hash('sha256', $contents),
                'nodes' => $nodes,
            ];
        }

        /** @var array<string, list<array{name: string, kind: string, edges: list<string>, source: array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}, node: Node\Stmt\Class_|Node\Stmt\Interface_}>> $declarations */
        $declarations = [];
        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                $declaration = self::declaration($statement, $source);
                if ($declaration !== null) {
                    $declarations[strtolower($declaration['name'])][] = $declaration;
                }
            }
        }
        $declarationMetadata = [];
        foreach ($declarations as $namedDeclarations) {
            foreach ($namedDeclarations as $declaration) {
                $declarationMetadata[] = self::location($declaration['node'], $declaration['source'])
                + [
                    'name' => $declaration['name'],
                    'kind' => $declaration['kind'],
                    'directParents' => $declaration['edges'],
                ];
            }
        }

        $candidates = [];
        $registrationCandidates = 0;
        $unsupportedRegistrationCandidates = 0;
        $finder = new NodeFinder;
        foreach ($sources as $source) {
            /** @var list<Node\Expr\MethodCall> $calls */
            $calls = $finder->findInstanceOf($source['nodes'], Node\Expr\MethodCall::class);
            foreach ($calls as $call) {
                if (! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $method = strtolower($call->name->toString());
                if (! in_array($method, ['bind', 'singleton', 'scoped'], true)) {
                    continue;
                }
                $registrationCandidates++;
                $arguments = self::registrationArguments($call);
                if ($arguments === null || ! $arguments[0]->value instanceof Node\Expr\ClassConstFetch) {
                    $unsupportedRegistrationCandidates++;
                    continue;
                }
                $abstract = self::classConstant($arguments[0]->value);
                if ($abstract === null) {
                    $unsupportedRegistrationCandidates++;
                    continue;
                }
                if (count($candidates) >= self::MAX_CANDIDATES) {
                    $reasons['candidate-limit'] = true;
                    break 2;
                }

                $concreteExpression = $arguments[1]->value;
                $concrete = $concreteExpression instanceof Node\Expr\ClassConstFetch
                    ? self::classConstant($concreteExpression)
                    : null;
                $compatibility = $concrete === null
                    ? ['status' => 'unknown-custom-factory', 'hierarchyComplete' => false, 'path' => []]
                    : self::compatibility($abstract, $concrete, $declarations);
                $candidates[] = [
                    'confidence' => 'source-only-declaration-policy-candidate',
                    'runtimeContainerValidated' => false,
                    'runtimeFailureClaimed' => false,
                    'registration' => self::location($call, $source) + ['method' => $method],
                    'abstract' => self::location($arguments[0]->value, $source) + ['class' => $abstract],
                    'concrete' => self::location($concreteExpression, $source)
                        + [
                            'kind' => $concrete === null ? 'custom-or-dynamic-factory' : 'literal-class',
                            'class' => $concrete,
                        ],
                    'compatibility' => $compatibility,
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'binding-compatibility-candidates',
                'evidence' => 'selected-source-declarations',
                'semantics' => 'optional-declaration-quality-advisory',
                'exhaustive' => false,
                'runtimeContainerValidated' => false,
                'runtimeFailureClaimed' => false,
                'altersInferredTypes' => false,
            ],
            'declarations' => $declarationMetadata,
            'candidates' => $candidates,
            'selection' => [
                'registrationCandidates' => $registrationCandidates,
                'exportedCandidates' => count($candidates),
                'unsupportedRegistrationCandidates' => $unsupportedRegistrationCandidates,
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
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array{name: string, kind: string, edges: list<string>, source: array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}, node: Node\Stmt\Class_|Node\Stmt\Interface_}|null
     */
    private static function declaration(Node\Stmt $statement, array $source): ?array
    {
        if ($statement instanceof Node\Stmt\Class_ && $statement->namespacedName !== null) {
            $edges = $statement->extends === null ? [] : [$statement->extends->toString()];
            foreach ($statement->implements as $interface) {
                $edges[] = $interface->toString();
            }

            return [
                'name' => $statement->namespacedName->toString(),
                'kind' => $statement->isAbstract() ? 'abstract-class' : 'class',
                'edges' => $edges,
                'source' => $source,
                'node' => $statement,
            ];
        }
        if ($statement instanceof Node\Stmt\Interface_ && $statement->namespacedName !== null) {
            $edges = [];
            foreach ($statement->extends as $interface) {
                $edges[] = $interface->toString();
            }

            return [
                'name' => $statement->namespacedName->toString(),
                'kind' => 'interface',
                'edges' => $edges,
                'source' => $source,
                'node' => $statement,
            ];
        }

        return null;
    }

    /** @return array{Node\Arg, Node\Arg}|null */
    private static function registrationArguments(Node\Expr\MethodCall $call): ?array
    {
        if (count($call->args) !== 2) {
            return null;
        }
        [$first, $second] = $call->args;
        if (! $first instanceof Node\Arg || ! $second instanceof Node\Arg || $first->unpack || $second->unpack) {
            return null;
        }
        if ($first->name === null && $second->name === null) {
            return [$first, $second];
        }
        if (
            $first->name?->toString() === 'abstract'
            && $second->name?->toString() === 'concrete'
        ) {
            return [$first, $second];
        }

        return null;
    }

    private static function classConstant(Node\Expr\ClassConstFetch $expression): ?string
    {
        return $expression->class instanceof Node\Name
        && $expression->name instanceof Node\Identifier
        && strtolower($expression->name->toString()) === 'class'
            ? $expression->class->toString()
            : null;
    }

    /**
     * @param array<string, list<array{name: string, kind: string, edges: list<string>, source: array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}, node: Node\Stmt\Class_|Node\Stmt\Interface_}>> $declarations
     * @return array{status: string, hierarchyComplete: bool, path: list<string>}
     */
    private static function compatibility(string $abstract, string $concrete, array $declarations): array
    {
        $result = self::walk($concrete, $abstract, $declarations, []);
        if ($result['matched']) {
            return ['status' => 'compatible', 'hierarchyComplete' => $result['complete'], 'path' => $result['path']];
        }
        if (count($declarations[strtolower($abstract)] ?? []) !== 1) {
            return ['status' => 'unknown-abstract-declaration', 'hierarchyComplete' => false, 'path' => []];
        }

        return [
            'status' => $result['complete'] ? 'incompatible-under-selected-declarations' : 'unknown-hierarchy',
            'hierarchyComplete' => $result['complete'],
            'path' => [],
        ];
    }

    /**
     * @param array<string, list<array{name: string, kind: string, edges: list<string>, source: array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}, node: Node\Stmt\Class_|Node\Stmt\Interface_}>> $declarations
     * @param array<string, true> $visiting
     * @return array{matched: bool, complete: bool, path: list<string>}
     */
    private static function walk(string $current, string $target, array $declarations, array $visiting): array
    {
        $isTarget = strcasecmp($current, $target) === 0;
        $key = strtolower($current);
        if (isset($visiting[$key])) {
            return ['matched' => false, 'complete' => false, 'path' => []];
        }
        $matches = $declarations[$key] ?? [];
        if (count($matches) !== 1) {
            return ['matched' => $isTarget, 'complete' => false, 'path' => $isTarget ? [$current] : []];
        }
        $visiting[$key] = true;
        $complete = true;
        $matched = $isTarget;
        $path = $isTarget ? [$current] : [];
        foreach ($matches[0]['edges'] as $edge) {
            $result = self::walk($edge, $target, $declarations, $visiting);
            if ($result['matched'] && ! $matched) {
                $matched = true;
                $path = array_merge([$matches[0]['name']], $result['path']);
            }
            $complete = $complete && $result['complete'];
        }

        return ['matched' => $matched, 'complete' => $complete, 'path' => $path];
    }

    /**
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array{file: string, start: int, end: int, line: int, contentHash: string}
     */
    private static function location(Node $node, array $source): array
    {
        return [
            'file' => $source['file'],
            'start' => $node->getStartFilePos(),
            'end' => $node->getEndFilePos() + 1,
            'line' => $node->getStartLine(),
            'contentHash' => $source['contentHash'],
        ];
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return ['code' => $code, 'file' => $file, 'message' => 'Selected source could not be exported safely.'];
    }
}
