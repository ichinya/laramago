<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Source-only candidates linking literal policy mappings to declared method
 * parameters. The export describes declarations, not effective Gate dispatch.
 */
final class PolicyModelArgumentContractExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_MAPPINGS = 10000;
    private const MAX_CONTRACTS = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP provider and policy sources.
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
            throw new \InvalidArgumentException('Policy sources must be a list of project-relative PHP files.');
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
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

        /**
         * @var array<string, list<array{
         *     name: string,
         *     class: Node\Stmt\Class_,
         *     source: array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}
         * }>> $classes
         */
        $classes = [];
        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                if ($statement instanceof Node\Stmt\Class_ && $statement->namespacedName !== null) {
                    $classes[strtolower($statement->namespacedName->toString())][] = [
                        'name' => $statement->namespacedName->toString(),
                        'class' => $statement,
                        'source' => $source,
                    ];
                }
            }
        }

        $mappings = [];
        $providerClasses = 0;
        $unsupportedMappingCandidates = 0;
        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                if (! $statement instanceof Node\Stmt\Class_ || ! self::isAuthServiceProvider($statement)) {
                    continue;
                }
                $providerName = $statement->namespacedName;
                if ($providerName === null) {
                    continue;
                }
                $providerClasses++;
                $property = null;
                foreach ($statement->getProperties() as $candidate) {
                    foreach ($candidate->props as $prop) {
                        if ($prop->name->toString() === 'policies') {
                            $property = $candidate;
                        }
                    }
                }
                if (
                    ! $property instanceof Node\Stmt\Property
                    || ! $property->isProtected()
                    || count($property->props) !== 1
                    || ! $property->props[0]->default instanceof Node\Expr\Array_
                ) {
                    $unsupportedMappingCandidates++;
                    continue;
                }
                foreach ($property->props[0]->default->items as $item) {
                    if (count($mappings) >= self::MAX_MAPPINGS) {
                        $reasons['mapping-limit'] = true;
                        break 3;
                    }
                    if ($item->key === null || $item->unpack || $item->byRef) {
                        $unsupportedMappingCandidates++;
                        continue;
                    }
                    $model = self::className($item->key);
                    $policy = self::className($item->value);
                    if ($model === null || $policy === null) {
                        $unsupportedMappingCandidates++;
                        continue;
                    }
                    $mappings[] = [
                        'model' => $model,
                        'policy' => $policy,
                        'provider' => $providerName->toString(),
                        'file' => $source['file'],
                        'start' => $item->getStartFilePos(),
                        'end' => $item->getEndFilePos() + 1,
                        'line' => $item->getStartLine(),
                        'contentHash' => $source['contentHash'],
                        'confidence' => 'literal-mapping-candidate',
                    ];
                }
            }
        }

        $contracts = [];
        $matchedMappings = 0;
        $unmatchedMappings = 0;
        $ambiguousMappings = 0;
        foreach ($mappings as $mapping) {
            $declarations = $classes[strtolower(ltrim($mapping['policy'], '\\'))] ?? [];
            if (count($declarations) === 0) {
                $unmatchedMappings++;
                continue;
            }
            if (count($declarations) !== 1) {
                $ambiguousMappings++;
                continue;
            }
            $matchedMappings++;
            $declaration = $declarations[0];
            foreach ($declaration['class']->getMethods() as $method) {
                if (! self::policyMethodCandidate($method)) {
                    continue;
                }
                if (count($contracts) >= self::MAX_CONTRACTS) {
                    $reasons['contract-limit'] = true;
                    break 2;
                }
                $parameter = $method->params[1] ?? null;
                $contracts[] = [
                    'confidence' => 'source-declaration-candidate',
                    'runtimeDispatchValidated' => false,
                    'mapping' => $mapping,
                    'policyMethod' => [
                        'class' => $declaration['name'],
                        'method' => $method->name->toString(),
                        'static' => $method->isStatic(),
                        'file' => $declaration['source']['file'],
                        'start' => $method->getStartFilePos(),
                        'end' => $method->getEndFilePos() + 1,
                        'line' => $method->getStartLine(),
                        'contentHash' => $declaration['source']['contentHash'],
                    ],
                    'modelParameterCandidate' => $parameter instanceof Node\Param
                        ? self::parameter($parameter, $mapping['model'], $declaration['source'])
                        : null,
                    'declarationCompatibility' => $parameter instanceof Node\Param
                        ? self::compatibility($parameter->type, $mapping['model'])
                        : 'not-present',
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'policy-model-argument-contract-candidates',
                'evidence' => 'source-only',
                'semantics' => 'literal-mapping-to-second-declared-policy-parameter',
                'exhaustive' => false,
                'runtimeDispatchValidated' => false,
            ],
            'mappings' => $mappings,
            'contracts' => $contracts,
            'selection' => [
                'providerClasses' => $providerClasses,
                'literalMappings' => count($mappings),
                'matchedMappings' => $matchedMappings,
                'unmatchedMappings' => $unmatchedMappings,
                'ambiguousMappings' => $ambiguousMappings,
                'unsupportedMappingCandidates' => $unsupportedMappingCandidates,
                'contractCandidates' => count($contracts),
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

    /** @param array<array-key, Node> $nodes
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

    private static function isAuthServiceProvider(Node\Stmt\Class_ $statement): bool
    {
        return (
            $statement->namespacedName !== null
            && ! $statement->isAbstract()
            && $statement->extends instanceof Node\Name
            && strcasecmp(
                $statement->extends->toString(),
                'Illuminate\\Foundation\\Support\\Providers\\AuthServiceProvider',
            ) === 0
        );
    }

    private static function className(Node\Expr $expression): ?string
    {
        if (
            $expression instanceof Node\Scalar\String_
            && preg_match(
                '~^\\\\?[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*(?:\\\\[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*)*$~',
                $expression->value,
            ) === 1
        ) {
            return $expression->value;
        }
        if (
            ! $expression instanceof Node\Expr\ClassConstFetch
            || ! $expression->class instanceof Node\Name\FullyQualified
            || ! $expression->name instanceof Node\Identifier
            || strcasecmp($expression->name->toString(), 'class') !== 0
        ) {
            return null;
        }

        return $expression->class->toString();
    }

    private static function policyMethodCandidate(Node\Stmt\ClassMethod $method): bool
    {
        return $method->isPublic()
        && ! $method->isAbstract()
        && ! in_array(
            strtolower($method->name->toString()),
            [
                '__construct',
                '__destruct',
                '__call',
                '__callstatic',
                'before',
                'allow',
                'deny',
            ],
            true,
        );
    }

    /**
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array<string, mixed>
     */
    private static function parameter(Node\Param $parameter, string $model, array $source): array
    {
        $variable = $parameter->var;
        $type = $parameter->type;
        $nativeType = $type === null
            ? null
            : substr(
                $source['contents'],
                $type->getStartFilePos(),
                $type->getEndFilePos() - $type->getStartFilePos() + 1,
            );

        return [
            'position' => 1,
            'positionAfterUser' => 0,
            'name' => $variable instanceof Node\Expr\Variable && is_string($variable->name)
                ? $variable->name
                : null,
            'start' => $variable->getStartFilePos(),
            'end' => $variable->getEndFilePos() + 1,
            'line' => $variable->getStartLine(),
            'nativeType' => $nativeType,
            'resolvedNativeType' => self::resolvedType($type),
            'nativeTypeSpan' => $type === null
                ? null
                : [
                    'start' => $type->getStartFilePos(),
                    'end' => $type->getEndFilePos() + 1,
                    'line' => $type->getStartLine(),
                ],
            'mappedModel' => $model,
            'hasDefault' => $parameter->default !== null,
            'variadic' => $parameter->variadic,
            'byReference' => $parameter->byRef,
        ];
    }

    private static function resolvedType(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return '?'.(string) self::resolvedType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(
                static fn ($member): string => $member instanceof Node\IntersectionType
                    ? '('.(string) self::resolvedType($member).')'
                    : (string) self::resolvedType($member),
                $type->types,
            ));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(
                static fn ($member): string => (string) self::resolvedType($member),
                $type->types,
            ));
        }

        return $type instanceof Node\Identifier || $type instanceof Node\Name ? $type->toString() : null;
    }

    private static function compatibility(Node\Identifier|Node\Name|Node\ComplexType|null $type, string $model): string
    {
        $state = self::typeState($type, ltrim($model, '\\'));

        return match ($state) {
            1 => 'compatible',
            -1 => 'incompatible',
            default => 'unknown',
        };
    }

    /** 1 accepts the mapped object, -1 rejects every object, 0 needs hierarchy/runtime knowledge. */
    private static function typeState(Node\Identifier|Node\Name|Node\ComplexType|null $type, string $model): int
    {
        if ($type === null) {
            return 1;
        }
        if ($type instanceof Node\NullableType) {
            return self::typeState($type->type, $model);
        }
        if ($type instanceof Node\UnionType) {
            $states = array_map(static fn ($member): int => self::typeState($member, $model), $type->types);
            if (in_array(1, $states, true)) {
                return 1;
            }

            return count(array_unique($states)) === 1 && $states[0] === -1 ? -1 : 0;
        }
        if ($type instanceof Node\IntersectionType) {
            $states = array_map(static fn ($member): int => self::typeState($member, $model), $type->types);
            if (in_array(-1, $states, true)) {
                return -1;
            }

            return count(array_unique($states)) === 1 && $states[0] === 1 ? 1 : 0;
        }
        if ($type instanceof Node\Name) {
            return strcasecmp(ltrim($type->toString(), '\\'), $model) === 0 ? 1 : 0;
        }

        if (! $type instanceof Node\Identifier) {
            return 0;
        }
        $identifier = strtolower($type->toString());
        if (in_array($identifier, ['mixed', 'object'], true)) {
            return 1;
        }

        return in_array($identifier, ['array', 'bool', 'false', 'float', 'int', 'null', 'true'], true) ? -1 : 0;
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'invalid-source' => 'Source must be a project-relative PHP file within the project root.',
                'parse-failure' => 'Unable to parse selected PHP source.',
                'source-limit' => 'Selected PHP source exceeds the bounded read budget.',
                default => 'Unable to read selected PHP source.',
            },
        ];
    }
}
