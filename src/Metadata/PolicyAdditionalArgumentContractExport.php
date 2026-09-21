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
 * Source-only policy parameter and Gate argument-list provenance.
 *
 * Policy mappings and the model parameter are delegated to
 * PolicyModelArgumentContractExport. This export adds only parameters after
 * that mapped model and direct native Gate facade call candidates. It does not
 * prove that any call reaches any policy method.
 */
final class PolicyAdditionalArgumentContractExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_CALLS = 10000;
    private const MAX_PARAMETERS = 20000;
    private const MAX_ARGUMENTS = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative provider, policy and call-site sources.
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        /**
         * @var array{
         *     schemaVersion: int,
         *     contracts: list<array{
         *         modelParameterCandidate: ?array<string, mixed>,
         *         mapping: array{file: string, start: int, end: int, contentHash: string},
         *         policyMethod: array{
         *             class: string,
         *             method: string,
         *             static: bool,
         *             file: string,
         *             start: int,
         *             end: int,
         *             line: int,
         *             contentHash: string
         *         }
         *     }>,
         *     errors: list<array<string, mixed>>,
         *     truncated: bool,
         *     truncationReasons: list<string>
         * } $modelExport
         */
        $modelExport = (new PolicyModelArgumentContractExport)->export($root, $files);

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

        $methods = self::methodIndex($sources);
        $policyContracts = [];
        $parameterCount = 0;
        $stalePolicyContracts = 0;
        foreach ($modelExport['contracts'] as $modelContract) {
            if ($modelContract['modelParameterCandidate'] === null) {
                continue;
            }
            $method = $modelContract['policyMethod'];
            $identity = self::methodIdentity(
                $method['class'],
                $method['method'],
                $method['file'],
                $method['start'],
                $method['end'],
                $method['contentHash'],
            );
            $declaration = $methods[$identity] ?? null;
            if ($declaration === null) {
                $stalePolicyContracts++;
                continue;
            }
            $additional = array_slice($declaration['method']->params, 2);
            if (($parameterCount + count($additional)) > self::MAX_PARAMETERS) {
                $reasons['parameter-limit'] = true;
                break;
            }
            $parameterCount += count($additional);
            $parameters = [];
            $position = 2;
            foreach ($additional as $parameter) {
                $parameters[] = self::parameter($parameter, $position, $declaration['source']);
                $position++;
            }
            $policyContracts[] = [
                'confidence' => 'source-declaration-candidate',
                'runtimeDispatchValidated' => false,
                'mappingRef' => [
                    'file' => $modelContract['mapping']['file'],
                    'start' => $modelContract['mapping']['start'],
                    'end' => $modelContract['mapping']['end'],
                    'contentHash' => $modelContract['mapping']['contentHash'],
                ],
                'policyMethodRef' => $method,
                'additionalParameterCandidates' => $parameters,
            ];
        }

        $calls = [];
        $argumentCount = 0;
        $unknownArgumentLists = 0;
        foreach ($sources as $source) {
            $finder = new NodeFinder;
            /** @var list<Node\Expr\StaticCall> $staticCalls */
            $staticCalls = $finder->findInstanceOf($source['nodes'], Node\Expr\StaticCall::class);
            foreach ($staticCalls as $call) {
                $candidate = self::gateCall($call, $source);
                if ($candidate === null) {
                    continue;
                }
                if (count($calls) >= self::MAX_CALLS) {
                    $reasons['call-limit'] = true;
                    break 2;
                }
                $nextArguments = $argumentCount + count($candidate['arguments']['additionalArguments']);
                if ($nextArguments > self::MAX_ARGUMENTS) {
                    $reasons['argument-limit'] = true;
                    break 2;
                }
                $argumentCount = $nextArguments;
                if (! $candidate['arguments']['complete']) {
                    $unknownArgumentLists++;
                }
                $calls[] = $candidate;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'policy-additional-argument-contract-candidates',
                'evidence' => 'source-only',
                'semantics' => 'mapped-policy-parameters-after-model-and-unjoined-gate-argument-lists',
                'exhaustive' => false,
                'runtimeDispatchValidated' => false,
            ],
            'policyContracts' => $policyContracts,
            'gateCalls' => $calls,
            'selection' => [
                'modelContractCandidates' => count($modelExport['contracts']),
                'policyContractsWithModelParameterCandidate' => count($policyContracts),
                'stalePolicyContracts' => $stalePolicyContracts,
                'additionalParameterCandidates' => $parameterCount,
                'gateCallCandidates' => count($calls),
                'additionalArgumentCandidates' => $argumentCount,
                'unknownArgumentLists' => $unknownArgumentLists,
            ],
            'dependency' => [
                'kind' => 'policy-model-argument-contract-candidates',
                'schemaVersion' => $modelExport['schemaVersion'],
                'errors' => $modelExport['errors'],
                'truncated' => $modelExport['truncated'],
                'truncationReasons' => $modelExport['truncationReasons'],
            ],
            'errors' => $errors,
            'truncated' => $reasons !== [] || $modelExport['truncated'],
            'truncationReasons' => array_values(array_unique([
                ...array_keys($reasons),
                ...array_map(
                    static fn (string $reason): string => 'dependency-'.$reason,
                    $modelExport['truncationReasons'],
                ),
            ])),
        ];
    }

    /**
     * @param list<array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}> $sources
     * @return array<string, array{method: Node\Stmt\ClassMethod, source: array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>}}>
     */
    private static function methodIndex(array $sources): array
    {
        $methods = [];
        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                if (! $statement instanceof Node\Stmt\Class_ || $statement->namespacedName === null) {
                    continue;
                }
                foreach ($statement->getMethods() as $method) {
                    $identity = self::methodIdentity(
                        $statement->namespacedName->toString(),
                        $method->name->toString(),
                        $source['file'],
                        $method->getStartFilePos(),
                        $method->getEndFilePos() + 1,
                        $source['contentHash'],
                    );
                    $methods[$identity] = ['method' => $method, 'source' => $source];
                }
            }
        }

        return $methods;
    }

    private static function methodIdentity(
        string $class,
        string $method,
        string $file,
        int $start,
        int $end,
        string $hash,
    ): string {
        return strtolower($class)."\0".strtolower($method)."\0".$file."\0".$start."\0".$end."\0".$hash;
    }

    /**
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array{
     *     confidence: string,
     *     runtimeDispatchValidated: bool,
     *     gate: array{method: string, file: string, start: int, end: int, line: int, contentHash: string},
     *     ability: array{value: string, start: int, end: int, line: int},
     *     arguments: array{
     *         state: string,
     *         complete: bool,
     *         subject: ?array{expressionKind: string, start: int, end: int, line: int},
     *         additionalArguments: list<array{
     *             positionInGateArguments: int,
     *             positionAfterSubject: int,
     *             expressionKind: string,
     *             start: int,
     *             end: int,
     *             line: int
     *         }>,
     *         start: ?int,
     *         end: ?int,
     *         line: ?int
     *     }
     * }|null
     */
    private static function gateCall(Node\Expr\StaticCall $call, array $source): ?array
    {
        if (
            ! $call->class instanceof Node\Name\FullyQualified
            || strcasecmp($call->class->toString(), 'Illuminate\Support\Facades\Gate') !== 0
            || ! $call->name instanceof Node\Identifier
            || ! in_array(
                strtolower($call->name->toString()),
                ['allows', 'denies', 'check', 'authorize', 'inspect', 'raw'],
                true,
            )
            || count($call->args) < 1
            || count($call->args) > 2
            || ! array_is_list($call->args)
        ) {
            return null;
        }
        $callArguments = [];
        foreach ($call->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->unpack || $argument->byRef) {
                return null;
            }
            $callArguments[] = $argument;
        }
        $ability = $callArguments[0]->value;
        if (! $ability instanceof Node\Scalar\String_) {
            return null;
        }

        $arguments = self::arguments(isset($callArguments[1]) ? $callArguments[1]->value : null);

        return [
            'confidence' => 'source-call-candidate',
            'runtimeDispatchValidated' => false,
            'gate' => [
                'method' => $call->name->toString(),
                'file' => $source['file'],
                'start' => $call->getStartFilePos(),
                'end' => $call->getEndFilePos() + 1,
                'line' => $call->getStartLine(),
                'contentHash' => $source['contentHash'],
            ],
            'ability' => [
                'value' => $ability->value,
                'start' => $ability->getStartFilePos(),
                'end' => $ability->getEndFilePos() + 1,
                'line' => $ability->getStartLine(),
            ],
            'arguments' => $arguments,
        ];
    }

    /**
     * @return array{
     *     state: string,
     *     complete: bool,
     *     subject: ?array{expressionKind: string, start: int, end: int, line: int},
     *     additionalArguments: list<array{
     *         positionInGateArguments: int,
     *         positionAfterSubject: int,
     *         expressionKind: string,
     *         start: int,
     *         end: int,
     *         line: int
     *     }>,
     *     start: ?int,
     *     end: ?int,
     *     line: ?int
     * }
     */
    private static function arguments(?Node\Expr $expression): array
    {
        if ($expression === null) {
            return [
                'state' => 'omitted',
                'complete' => true,
                'subject' => null,
                'additionalArguments' => [],
                'start' => null,
                'end' => null,
                'line' => null,
            ];
        }
        if (! $expression instanceof Node\Expr\Array_) {
            return [
                'state' => 'single-expression',
                'complete' => true,
                'subject' => self::expression($expression),
                'additionalArguments' => [],
                'start' => $expression->getStartFilePos(),
                'end' => $expression->getEndFilePos() + 1,
                'line' => $expression->getStartLine(),
            ];
        }
        $base = [
            'start' => $expression->getStartFilePos(),
            'end' => $expression->getEndFilePos() + 1,
            'line' => $expression->getStartLine(),
        ];
        foreach ($expression->items as $item) {
            if ($item->key !== null || $item->unpack || $item->byRef) {
                return [
                    'state' => 'non-list-array',
                    'complete' => false,
                    'subject' => null,
                    'additionalArguments' => [],
                    ...$base,
                ];
            }
        }
        $items = array_values($expression->items);
        $subject = $items === [] ? null : self::expression($items[0]->value);
        $additional = [];
        foreach (array_slice($items, 1) as $offset => $item) {
            $additional[] = [
                'positionInGateArguments' => $offset + 1,
                'positionAfterSubject' => $offset,
                ...self::expression($item->value),
            ];
        }

        return [
            'state' => 'list-array',
            'complete' => true,
            'subject' => $subject,
            'additionalArguments' => $additional,
            ...$base,
        ];
    }

    /** @return array{expressionKind: string, start: int, end: int, line: int} */
    private static function expression(Node\Expr $expression): array
    {
        return [
            'expressionKind' => match (true) {
                $expression instanceof Node\Expr\Variable => 'variable',
                $expression instanceof Node\Expr\ClassConstFetch => 'class-constant-fetch',
                $expression instanceof Node\Expr\New_ => 'new-expression',
                $expression instanceof Node\Scalar => 'literal',
                $expression instanceof Node\Expr\Array_ => 'array-expression',
                $expression instanceof Node\Expr\Closure, $expression instanceof Node\Expr\ArrowFunction => 'closure',
                $expression instanceof Node\Expr\CallLike => 'call-expression',
                default => 'expression',
            },
            'start' => $expression->getStartFilePos(),
            'end' => $expression->getEndFilePos() + 1,
            'line' => $expression->getStartLine(),
        ];
    }

    /**
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array<string, mixed>
     */
    private static function parameter(Node\Param $parameter, int $position, array $source): array
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
            'position' => $position,
            'positionAfterUser' => $position - 1,
            'positionAfterModel' => $position - 2,
            'name' => $variable instanceof Node\Expr\Variable && is_string($variable->name) ? $variable->name : null,
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
