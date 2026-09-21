<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Comment\Doc;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Source candidates for Laravel's built-in contextual parameter attributes.
 *
 * The export deliberately does not infer an injected value or an effective
 * container resolution path. Application calls, callbacks, custom containers,
 * and registered contextual attribute handlers can all change runtime behavior.
 */
final class ContainerInjectionAttributeExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_CONTRACTS = 20000;

    /** @var array<string, string> */
    private const KINDS = [
        'Illuminate\\Container\\Attributes\\Auth' => 'auth',
        'Illuminate\\Container\\Attributes\\Authenticated' => 'authenticated',
        'Illuminate\\Container\\Attributes\\Cache' => 'cache',
        'Illuminate\\Container\\Attributes\\Config' => 'config',
        'Illuminate\\Container\\Attributes\\Context' => 'context',
        'Illuminate\\Container\\Attributes\\CurrentUser' => 'current-user',
        'Illuminate\\Container\\Attributes\\Database' => 'database',
        'Illuminate\\Container\\Attributes\\DB' => 'db',
        'Illuminate\\Container\\Attributes\\Give' => 'give',
        'Illuminate\\Container\\Attributes\\Log' => 'log',
        'Illuminate\\Container\\Attributes\\RequestAttribute' => 'request-attribute',
        'Illuminate\\Container\\Attributes\\RouteParameter' => 'route-parameter',
        'Illuminate\\Container\\Attributes\\Storage' => 'storage',
        'Illuminate\\Container\\Attributes\\Tag' => 'tag',
    ];

    /** @var array<string, list<string>> */
    private const ARGUMENT_ROLES = [
        'auth' => ['guard'],
        'authenticated' => ['guard'],
        'cache' => ['store', 'memo'],
        'config' => ['key', 'default'],
        'context' => ['key', 'default', 'hidden'],
        'current-user' => ['guard'],
        'database' => ['connection'],
        'db' => ['connection'],
        'give' => ['class', 'params'],
        'log' => ['channel', 'name'],
        'request-attribute' => ['parameter'],
        'route-parameter' => ['parameter'],
        'storage' => ['disk'],
        'tag' => ['tag'],
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
                'Container injection sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $contracts = [];
        $errors = [];
        $reasons = [];
        $seen = [];
        $totalBytes = 0;
        $filesRead = 0;
        $parametersScanned = 0;
        $recognizedAttributes = 0;

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
                $reasons[$totalBytes > self::MAX_TOTAL_BYTES ? 'total-byte-limit' : 'file-byte-limit'] = true;
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

            $filesRead++;
            $source = ['file' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents)];
            self::collect(
                $nodes,
                null,
                $source,
                $contracts,
                $parametersScanned,
                $recognizedAttributes,
                $reasons,
            );
            if (isset($reasons['contract-limit'])) {
                break;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'container-injection-attribute-candidates',
                'evidence' => 'source-only',
                'exhaustive' => false,
                'containerInvocationValidated' => false,
                'nativeFrameworkDeclarationValidated' => false,
                'effectiveContextualHandlerValidated' => false,
                'injectedValueTypeInferred' => false,
            ],
            'selection' => [
                'filesRequested' => count($files),
                'filesRead' => $filesRead,
                'parametersScanned' => $parametersScanned,
                'attributedParameters' => count($contracts),
                'recognizedAttributes' => $recognizedAttributes,
            ],
            'contracts' => $contracts,
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
     * @param array{file: string, contents: string, hash: string} $source
     * @param list<array<string, mixed>> $contracts
     * @param array<string, true> $reasons
     */
    private static function collect(
        array $nodes,
        ?string $class,
        array $source,
        array &$contracts,
        int &$parametersScanned,
        int &$recognizedAttributes,
        array &$reasons,
    ): void {
        $finder = new NodeFinder;
        $callables = [];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $classLike) {
            $className = isset($classLike->namespacedName) ? $classLike->namespacedName->toString() : $class;
            foreach ($classLike->getMethods() as $method) {
                $callables[] = ['node' => $method, 'kind' => 'method', 'class' => $className];
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
            $callables[] = ['node' => $function, 'kind' => 'function', 'class' => null];
        }
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Closure::class) as $closure) {
            $callables[] = ['node' => $closure, 'kind' => 'closure', 'class' => null];
        }
        foreach ($finder->findInstanceOf($nodes, Node\Expr\ArrowFunction::class) as $arrow) {
            $callables[] = ['node' => $arrow, 'kind' => 'arrow-function', 'class' => null];
        }
        usort(
            $callables,
            static fn (
                array $left,
                array $right,
            ): int => $left['node']->getStartFilePos() <=> $right['node']->getStartFilePos(),
        );
        foreach ($callables as $candidate) {
            self::callable(
                $candidate['node'],
                $candidate['kind'],
                $candidate['class'],
                $source,
                $contracts,
                $parametersScanned,
                $recognizedAttributes,
                $reasons,
            );
            if (isset($reasons['contract-limit'])) {
                return;
            }
        }
    }

    /**
     * @param array{file: string, contents: string, hash: string} $source
     * @param list<array<string, mixed>> $contracts
     * @param array<string, true> $reasons
     */
    private static function callable(
        Node\FunctionLike $callable,
        string $kind,
        ?string $class,
        array $source,
        array &$contracts,
        int &$parametersScanned,
        int &$recognizedAttributes,
        array &$reasons,
    ): void {
        $identity = self::callableIdentity($callable, $kind, $class);
        foreach (array_values($callable->getParams()) as $position => $parameter) {
            $parametersScanned++;
            $attributes = [];
            $otherAttributes = [];
            foreach ($parameter->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $attributeClass = $attribute->name->toString();
                    $attributeKind = self::KINDS[$attributeClass] ?? null;
                    $entry = self::attribute($attribute, $attributeClass, $attributeKind);
                    if ($attributeKind === null) {
                        $otherAttributes[] = $entry;
                    } else {
                        $attributes[] = $entry;
                        $recognizedAttributes++;
                    }
                }
            }
            if ($attributes === []) {
                continue;
            }
            if (count($contracts) >= self::MAX_CONTRACTS) {
                $reasons['contract-limit'] = true;

                return;
            }

            $contracts[] = [
                'file' => $source['file'],
                'contentHash' => $source['hash'],
                'callable' => $identity,
                'parameter' => self::parameter($parameter, $position, $source['contents']),
                'attributes' => $attributes,
                'otherAttributes' => $otherAttributes,
                'runtimeResolutionValidated' => false,
                'effectiveInjectedType' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private static function callableIdentity(Node\FunctionLike $callable, string $kind, ?string $class): array
    {
        $name = null;
        if ($callable instanceof Node\Stmt\ClassMethod) {
            $name = $callable->name->toString();
        } elseif ($callable instanceof Node\Stmt\Function_) {
            $name = isset($callable->namespacedName)
                ? $callable->namespacedName->toString()
                : $callable->name->toString();
        }

        $result = [
            'kind' => $kind,
            'class' => $class,
            'name' => $name,
            'start' => $callable->getStartFilePos(),
            'end' => $callable->getEndFilePos() + 1,
            'line' => $callable->getStartLine(),
            'phpDocSpan' => self::docSpan($callable->getDocComment()),
        ];

        return $result;
    }

    /** @return array{start: int, end: int, line: int}|null */
    private static function docSpan(?Doc $comment): ?array
    {
        if ($comment === null) {
            return null;
        }

        return [
            'start' => $comment->getStartFilePos(),
            'end' => $comment->getEndFilePos() + 1,
            'line' => $comment->getStartLine(),
        ];
    }

    /** @return array<string, mixed> */
    private static function parameter(Node\Param $parameter, int $position, string $contents): array
    {
        $variable = $parameter->var;
        $name = $variable instanceof Node\Expr\Variable && is_string($variable->name) ? $variable->name : '';
        $nativeType = null;
        $nativeTypeSpan = null;
        if ($parameter->type !== null) {
            $nativeType = substr(
                $contents,
                $parameter->type->getStartFilePos(),
                $parameter->type->getEndFilePos() - $parameter->type->getStartFilePos() + 1,
            );
            $nativeTypeSpan = self::span($parameter->type);
        }

        return [
            'position' => $position,
            'name' => $name,
            'start' => $variable->getStartFilePos(),
            'end' => $variable->getEndFilePos() + 1,
            'line' => $variable->getStartLine(),
            'nativeType' => $nativeType,
            'nativeTypeSpan' => $nativeTypeSpan,
            'resolvedNativeType' => self::resolvedType($parameter->type),
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
            $inner = self::resolvedType($type->type);

            return $inner === null ? null : '?'.$inner;
        }
        if ($type instanceof Node\UnionType) {
            $parts = [];
            foreach ($type->types as $member) {
                $part = self::resolvedType($member);
                if ($part === null) {
                    return null;
                }
                $parts[] = $part;
            }

            return implode('|', $parts);
        }
        if ($type instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($type->types as $member) {
                $part = self::resolvedType($member);
                if ($part === null) {
                    return null;
                }
                $parts[] = $part;
            }

            return implode('&', $parts);
        }

        return $type instanceof Node\Identifier || $type instanceof Node\Name ? $type->toString() : null;
    }

    /** @return array<string, mixed> */
    private static function attribute(Node\Attribute $attribute, string $class, ?string $kind): array
    {
        $arguments = [];
        foreach ($attribute->args as $position => $argument) {
            $arguments[] = self::argument($argument, $position, $kind);
        }

        return [
            'class' => $class,
            'kind' => $kind,
            'start' => $attribute->getStartFilePos(),
            'end' => $attribute->getEndFilePos() + 1,
            'line' => $attribute->getStartLine(),
            'arguments' => $arguments,
        ];
    }

    /** @return array<string, mixed> */
    private static function argument(Node\Arg $argument, int $position, ?string $kind): array
    {
        $name = $argument->name?->toString();
        $roles = $kind === null ? [] : self::ARGUMENT_ROLES[$kind] ?? [];
        $role = $name === null ? $roles[$position] ?? null : (in_array($name, $roles, true) ? $name : null);
        $expression = self::expression($argument->value);

        return [
            'position' => $position,
            'name' => $name,
            'nativeRoleCandidate' => $role,
            'unpack' => $argument->unpack,
            'byReference' => $argument->byRef,
            'start' => $argument->value->getStartFilePos(),
            'end' => $argument->value->getEndFilePos() + 1,
            'line' => $argument->value->getStartLine(),
            ...$expression,
        ];
    }

    /** @return array<string, mixed> */
    private static function expression(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Scalar\String_) {
            return ['expressionKind' => 'string-literal', 'literalValue' => $expression->value];
        }
        if ($expression instanceof Node\Scalar\Int_) {
            return ['expressionKind' => 'integer-literal', 'literalValue' => $expression->value];
        }
        if ($expression instanceof Node\Scalar\Float_) {
            return ['expressionKind' => 'float-literal', 'literalValue' => $expression->value];
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            $constant = strtolower($expression->name->toString());
            if (in_array($constant, ['true', 'false', 'null'], true)) {
                return [
                    'expressionKind' => $constant.'-literal',
                    'literalValue' => match ($constant) {
                        'true' => true,
                        'false' => false,
                        default => null,
                    },
                ];
            }

            return ['expressionKind' => 'constant', 'constant' => $expression->name->toString()];
        }
        if (
            $expression instanceof Node\Expr\ClassConstFetch
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
        ) {
            $constant = $expression->name->toString();

            return [
                'expressionKind' => strtolower($constant) === 'class' ? 'class-name' : 'class-constant',
                'class' => $expression->class->toString(),
                'constant' => $constant,
            ];
        }
        if ($expression instanceof Node\Expr\Array_) {
            return ['expressionKind' => 'array'];
        }

        return ['expressionKind' => 'dynamic'];
    }

    /** @return array{start: int, end: int, line: int} */
    private static function span(Node $node): array
    {
        return [
            'start' => $node->getStartFilePos(),
            'end' => $node->getEndFilePos() + 1,
            'line' => $node->getStartLine(),
        ];
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'parse-failure' => 'Unable to parse selected container injection source.',
                'source-limit' => 'Selected container injection source byte limit exceeded.',
                'invalid-source'
                    => 'Container injection source must be a project-relative PHP file contained in the project.',
                default => 'Unable to read selected container injection source.',
            },
        ];
    }
}
