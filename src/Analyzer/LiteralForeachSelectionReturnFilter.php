<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticReturnTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryResultContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralForeachSelection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Prove a nonnullable result selected by an exhaustive literal foreach. */
final class LiteralForeachSelectionReturnFilter implements IssueFilterHook, InitializationHook, CodebaseScanHook
{
    /** @var array<string, array<Node>|null> */
    private array $cache = [];
    private ?PhpSource $source = null;
    private ?FactoryResultContract $factories = null;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
        $this->source = null;
        $this->factories = null;
    }

    public function getCodes(): array
    {
        return ['nullable-return-statement', 'invalid-return-statement'];
    }

    public function getTargets(): array
    {
        return ['**'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        ($this->factories ??= new FactoryResultContract($this->root))->scan($context);
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (strlen($context->contents) > 1_000_000 || strlen($context->issue->message) > 16384) {
            return IssueFilterDecision::Keep;
        }
        $pattern = match ($context->issue->code) {
            'invalid-return-statement' => '/^Invalid return type for function `([^`]+)`: expected `([^`]+)`, but found `([^`]+)`\.$/D',
            'nullable-return-statement' => '/^Function `([^`]+)` is declared to return `([^`]+)` but possibly returns a nullable value \(inferred as `([^`]+)`\)\.$/D',
            default => null,
        };
        if ($pattern === null || preg_match($pattern, $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $expected = DiagnosticReturnTypes::parse($match[2]);
        $found = DiagnosticReturnTypes::parse($match[3]);
        if ($expected === null || $found === null) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            $message = $context->issue->code === 'invalid-return-statement'
                ? 'This has type `'.$match[3].'`' : 'Nullable value returned here.';
            if ($primary !== null || $annotation->file !== null && $annotation->file !== '' || $annotation->message !== $message) {
                return IssueFilterDecision::Keep;
            }
            $primary = $annotation;
        }
        if ($primary === null) {
            return IssueFilterDecision::Keep;
        }
        $nodes = $this->nodes($context->file, $context->contents);
        $sites = [];
        foreach (self::declarations($nodes ?? []) as [$owner, $name, $scope]) {
            $return = $scope->stmts[array_key_last($scope->stmts ?? [])] ?? null;
            $identifier = $owner === null ? $name : $owner.'::'.$name;
            if (strcasecmp($identifier, $match[1]) === 0 && $return instanceof Node\Stmt\Return_
                && $return->expr?->getStartFilePos() === $primary->span->start
                && $return->expr->getEndFilePos() + 1 === $primary->span->end) {
                $sites[] = [$owner, $name, $scope, $return];
            }
        }
        if (count($sites) !== 1) {
            return IssueFilterDecision::Keep;
        }
        [$owner, $name, $scope, $return] = $sites[0];
        $metadata = $owner === null ? $context->codebase->getFunction($name) : $context->codebase->getDeclaringMethod($owner, $name);
        $declared = DiagnosticArrayTypes::resolveAliases($metadata?->returnType?->type ?? Type::never(), $context->codebase);
        if ($metadata === null || $declared === null || $metadata->templates !== []
            || ! self::identity($metadata, $scope, $context->file, $owner, $name)
            || ! $this->nativeDeclaration($metadata, $scope, $context)
            || ! DiagnosticArrayTypes::same($expected, $declared, $context->types)) {
            return IssueFilterDecision::Keep;
        }
        $proof = LiteralForeachSelection::prove($scope, $return);
        if ($proof === null) {
            return IssueFilterDecision::Keep;
        }
        foreach ($proof['safeIntrinsics'] as $intrinsic) {
            $resolved = self::functionName($intrinsic, $context->codebase);
            $function = $resolved === null ? null : $context->codebase->getFunction($resolved);
            if ($resolved !== 'sprintf' || $function === null || ! $function->flags->contains(MetadataFlags::BUILTIN)) {
                return IssueFilterDecision::Keep;
            }
        }
        $producer = $proof['producer'];
        $proven = $producer instanceof Node\Expr\FuncCall
            ? $this->nativeFunctionResult($producer, $context, $nodes ?? [])
            : ($this->factories ??= new FactoryResultContract($this->root))->resolve($context->codebase, $producer, $owner);
        if ($proven === null || ! self::nullableObject($match[3], $proven)
            || ! DiagnosticArrayTypes::same($found, Type::union($proven, Type::null()), $context->types)
            || ! $context->types->isContainedBy($proven, $declared)
            || $metadata->declaredReturnType !== null && ! $context->types->isContainedBy($proven, $metadata->declaredReturnType->type)) {
            return IssueFilterDecision::Keep;
        }
        return IssueFilterDecision::Remove;
    }

    /** @param array<Node> $currentNodes */
    private function nativeFunctionResult(Node\Expr\FuncCall $call, IssueFilterContext $context, array $currentNodes): ?Type
    {
        $name = self::functionName($call, $context->codebase);
        $metadata = $name === null ? null : $context->codebase->getFunction($name);
        $type = $metadata?->declaredReturnType?->type;
        $atom = count($type?->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        if ($metadata === null || $name === null || $metadata->templates !== [] || ! $atom instanceof NamedObjectType
            || ($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== [] || $atom->isThis || $atom->static
            || $metadata->abstract || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || $metadata->flags->contains(MetadataFlags::BUILTIN)
            || $context->codebase->getClassLike($atom->name)?->hasIncompleteHierarchy() !== false) {
            return null;
        }
        foreach ($call->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
        }
        $file = $metadata->location->file;
        if ($file === null) {
            return null;
        }
        if (! $call->name instanceof Node\Name\FullyQualified && $call->name->isUnqualified()
            && strcasecmp($name, $call->name->toString()) !== 0 && self::path($file) !== self::path($context->file)) {
            // An external namespace function need not be loaded. An unqualified
            // call could instead dispatch to a different global fallback.
            return null;
        }
        $nodes = $currentNodes;
        if (self::path($file) !== self::path($context->file)) {
            $source = $this->source ??= new PhpSource($this->root);
            $path = self::readablePath($file);
            $size = @filesize($source->path($path));
            if ($size === false || $size > 1_000_000) {
                return null;
            }
            $nodes = $source->read($path);
        }
        $matches = [];
        foreach (self::declarations($nodes ?? []) as [$owner, $functionName, $syntax]) {
            if ($owner === null && strcasecmp($functionName, $name) === 0) {
                $matches[] = $syntax;
            }
        }
        if (count($matches) !== 1 || ! $matches[0] instanceof Node\Stmt\Function_
            || ! self::identity($metadata, $matches[0], $file, null, $name)
            || ! $matches[0]->returnType instanceof Node\Name
            || strcasecmp($matches[0]->returnType->toString(), $atom->name) !== 0) {
            return null;
        }
        return $type;
    }

    private static function nullableObject(string $expression, Type $proven): bool
    {
        $parts = DiagnosticArrayTypes::split($expression, '|');
        $atom = count($proven->atomicTypes) === 1 ? $proven->atomicTypes[0] : null;
        if (count($parts ?? []) !== 2 || ! $atom instanceof NamedObjectType || ! in_array('null', $parts, true)) {
            return false;
        }
        $object = $parts[0] === 'null' ? $parts[1] : $parts[0];
        return strcasecmp(ltrim($object, '\\'), $atom->name) === 0;
    }

    private static function functionName(Node\Expr\FuncCall $call, Codebase $codebase): ?string
    {
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable()) {
            return null;
        }
        if ($call->name instanceof Node\Name\FullyQualified) {
            return strtolower($call->name->toString());
        }
        $namespaced = $call->name->getAttribute('namespacedName');
        if (! $call->name->isUnqualified()) {
            return $namespaced instanceof Node\Name ? strtolower($namespaced->toString()) : null;
        }
        if ($namespaced instanceof Node\Name && $codebase->functionExists($namespaced->toString())) {
            return strtolower($namespaced->toString());
        }
        return strtolower($call->name->toString());
    }

    private static function identity(FunctionLikeMetadata $metadata, Node\Stmt\Function_|Node\Stmt\ClassMethod $scope, string $file, ?string $owner, string $name): bool
    {
        if ($scope->byRef || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || strcasecmp($metadata->identifier->class ?? '', $owner ?? '') !== 0
            || strcasecmp($metadata->identifier->name, $name) !== 0 || self::path($metadata->location->file ?? '') !== self::path($file)
            || $metadata->nameLocation?->span->start !== $scope->name->getStartFilePos()
            || $metadata->nameLocation?->span->end !== $scope->name->getEndFilePos() + 1
            || $metadata->location->span->start > $scope->getStartFilePos() || $metadata->location->span->end !== $scope->getEndFilePos() + 1
            || count($metadata->parameters) !== count($scope->params)
            || ($scope->returnType === null) !== ($metadata->declaredReturnType === null)) {
            return false;
        }
        foreach ($scope->params as $index => $parameter) {
            $input = $metadata->parameters[$index];
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)
                || $input->name !== '$'.$parameter->var->name
                || $input->flags->contains(MetadataFlags::BY_REFERENCE) !== $parameter->byRef
                || $input->flags->contains(MetadataFlags::VARIADIC) !== $parameter->variadic
                || $input->nameLocation->span->start !== $parameter->var->getStartFilePos()
                || $input->nameLocation->span->end !== $parameter->var->getEndFilePos() + 1) {
                return false;
            }
        }
        return true;
    }

    private function nativeDeclaration(FunctionLikeMetadata $metadata, Node\Stmt\Function_|Node\Stmt\ClassMethod $scope, IssueFilterContext $context): bool
    {
        if ($scope->returnType === null) {
            return $metadata->declaredReturnType === null;
        }
        $expression = self::nativeType($scope->returnType);
        $type = $expression === null ? null : DiagnosticReturnTypes::parse($expression);
        return $type !== null && $metadata->declaredReturnType !== null
            && DiagnosticArrayTypes::same($type, $metadata->declaredReturnType->type, $context->types);
    }

    private static function nativeType(Node $node): ?string
    {
        if ($node instanceof Node\NullableType) {
            $inner = self::nativeType($node->type);
            return $inner === null ? null : $inner.'|null';
        }
        if ($node instanceof Node\UnionType) {
            $parts = [];
            foreach ($node->types as $part) {
                $expression = self::nativeType($part);
                if ($expression === null) {
                    return null;
                }
                $parts[] = $expression;
            }
            return implode('|', $parts);
        }
        if ($node instanceof Node\Name) {
            return in_array(strtolower($node->toString()), ['self', 'static', 'parent'], true) ? null : $node->toString();
        }
        return $node instanceof Node\Identifier && in_array($node->toString(), ['object', 'mixed', 'void', 'never', 'null', 'false', 'true', 'string', 'int', 'float', 'bool'], true)
            ? $node->toString() : null;
    }

    /** @param array<Node> $nodes
     * @return iterable<array{?string,string,Node\Stmt\Function_|Node\Stmt\ClassMethod}>
     */
    private static function declarations(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::declarations($node->stmts);
            } elseif ($node instanceof Node\Stmt\Function_ && isset($node->namespacedName)) {
                yield [null, $node->namespacedName->toString(), $node];
            } elseif ($node instanceof Node\Stmt\Class_ && isset($node->namespacedName)) {
                foreach ($node->getMethods() as $method) {
                    yield [$node->namespacedName->toString(), $method->name->toString(), $method];
                }
            }
        }
    }

    /** @return array<Node>|null */
    private function nodes(string $file, string $contents): ?array
    {
        $key = hash('sha256', $file."\0".$contents);
        if (! array_key_exists($key, $this->cache)) {
            if (count($this->cache) >= 16) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
                $this->cache[$key] = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (Error) {
                $this->cache[$key] = null;
            }
        }
        return $this->cache[$key];
    }

    private static function readablePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return preg_match('~^//\\?/[A-Za-z]:/~', $path) === 1 ? substr($path, 4) : $path;
    }

    private static function path(string $path): string
    {
        $path = self::readablePath($path);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
