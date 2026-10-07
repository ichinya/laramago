<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Verify storage, effective declarations and the scalar-only native assertion success chain. */
final class AssertedPureGetterContracts
{
    private const ASSERT = 'Testo\\Assert';
    private const STATE = 'Testo\\Assert\\Internal\\StaticState';
    private const SUPPORT = 'Testo\\Assert\\Internal\\Support';
    private const COLLECTOR = 'Testo\\Assert\\TestState';
    private const RECORD = 'Testo\\Assert\\State\\Assertion\\AssertionSuccess';
    private const NATIVE_METHODS = [
        self::ASSERT => ['notNull' => '4a43156a6f5b1980df1b96e7b6d10c8d7a3bb4e7ddf2d1ccdaccc7429ceef6d8'],
        self::STATE => ['success' => '86b98bd33c465e170975fe5f982bb7676fab8d8f760a6a16a526d1d9ae4a9bca', 'fail' => 'ace6773745442d7b0b18849534b778c21dfa2f544c191259b53a206ccd72d64b'],
        self::SUPPORT => ['stringify' => 'e382d517dc861d6cf6f3d90a7e01dab6e5d791d13bad921261eec28f8cd97a4e'],
        self::RECORD => ['__construct' => '2b1a864e146c04939797e44e6e7f387c995f33ff645afcd218fa50659322bc79'],
    ];
    private const FILES = [
        self::ASSERT => '/testo/assert/Assert.php',
        self::STATE => '/testo/assert/src/Internal/StaticState.php',
        self::SUPPORT => '/testo/assert/src/Internal/Support.php',
        self::COLLECTOR => '/testo/assert/src/TestState.php',
        self::RECORD => '/testo/assert/src/State/Assertion/AssertionSuccess.php',
    ];
    private const NATIVE_DOCS = [
        self::ASSERT.'::notNull' => '605a59fdaabeb6a9e6de607e8e1f654eb4a1f77b0ecd79227baa3080146f902d',
        self::STATE.'::success' => 'ca950e0cc5822c081355f1734bf34a6bb3a6e8b30c05e547fa70133d37039628',
        self::STATE.'::fail' => '549e1d6944f45bb066c32fd6fb77430777ba8a06646c95378a13d789d66d43bf',
        self::SUPPORT.'::stringify' => '475d9f540ae6f31a5ca0046af07097a2902dfd41300bb9877b2ef1af47c4945c',
        self::RECORD.'::__construct' => '413a2b4386bb4fdf75e9bfd4f9cd091c309bcef6ee90bdd1236213d16e33bd0d',
        'history' => 'ae49898daed7826bfebd4f1a214ae62c1e4efbb332061ec23ccdc64470acbd82',
    ];

    public function __construct(private readonly AssertedPureGetterCalls $calls, private readonly PhpSource $source) {}

    /** @param array<string, mixed> $candidate */
    public function result(ReturnTypeProviderContext $context, array $candidate): ?Type
    {
        $call = $context->invocation;
        $atomic = $call->receiverType?->atomicTypes[0] ?? null;
        $flags = $call->receiverType?->flags;
        if (count($call->receiverType?->atomicTypes ?? []) !== 1 || ! $atomic instanceof NamedObjectType
            || $atomic->static || $atomic->isThis || ($atomic->parameters ?? []) !== [] || ($atomic->intersections ?? []) !== []
            || ($atomic->variances ?? []) !== [] || $atomic->remappedParameters
            || $flags?->possiblyUndefined || $flags?->possiblyUndefinedFromTry || $flags?->nullsafeNull
            || $flags?->hadTemplate || $flags?->fromUnspecifiedTemplate
            || strcasecmp($call->declaringClass ?? '', $atomic->name) !== 0
            || ! $this->caller($context, $candidate)) { return null; }
        $class = $context->codebase->getClassLike($atomic->name);
        $node = $class === null ? null : $this->classNode($class, true);
        if (! $node instanceof Node\Stmt\Class_ || ! $node->isFinal() || ! $class->flags->contains(MetadataFlags::FINAL)
            || $class->kind !== ClassLikeKind::Class_ || $class->templates !== [] || $class->typeAliases !== []
            || $class->magicProperties !== [] || $class->pseudoMethods !== [] || $class->staticPseudoMethods !== []
            || $class->mixins !== [] || $class->attributes !== [] || $class->usedTraits !== []
            || $class->directParentClass !== null || $node->extends !== null || $node->getTraitUses() !== []
            || $context->codebase->getDeclaringMethod($atomic->name, '__get') !== null
            || $context->codebase->getDeclaringMethod($atomic->name, '__set') !== null) { return null; }
        $ancestors = $context->codebase->getClassAncestors($atomic->name);
        if (count($ancestors) > 64) { return null; }
        foreach ($ancestors as $ancestor) {
            $metadata = $context->codebase->getClassLike($ancestor);
            if ($metadata === null || $this->classNode($metadata, true) === null) { return null; }
        }
        $getter = $context->codebase->getDeclaringMethod($atomic->name, $call->name);
        $syntax = $node->getMethod($call->name);
        $nullable = Type::union(Type::string(), Type::null());
        if ($getter === null || $syntax === null || ! $this->method($getter, $syntax, $class->location->file ?? '', $atomic->name)
            || $getter->static || $getter->abstract || $getter->visibility !== Visibility::Public || $getter->parameters !== [] || $getter->templates !== []
            || $getter->attributes !== [] || $getter->globalsAccessed !== [] || $getter->assertions !== []
            || $getter->ifTrueAssertions !== [] || $getter->ifFalseAssertions !== [] || $getter->assertionsInferred
            || $syntax->byRef || $syntax->isStatic() || $syntax->params !== [] || $syntax->attrGroups !== []
            || $getter->returnType === null || $getter->declaredReturnType === null
            || $getter->returnType->fromDocblock || $getter->declaredReturnType->fromDocblock
            || ! self::nullableString($syntax->returnType)
            || ! self::located($getter->declaredReturnType->location, $syntax->returnType, $class->location->file ?? '', $this->calls)
            || ! self::located($getter->returnType->location, $syntax->returnType, $class->location->file ?? '', $this->calls)
            || ! $context->types->equals($getter->declaredReturnType->type, $nullable)
            || ! $context->types->equals($getter->returnType->type, $nullable)
            || count($syntax->stmts ?? []) !== 1 || ! $syntax->stmts[0] instanceof Node\Stmt\Return_) { return null; }
        $read = $syntax->stmts[0]->expr;
        if (! $read instanceof Node\Expr\PropertyFetch || ! $read->var instanceof Node\Expr\Variable
            || $read->var->name !== 'this' || ! $read->name instanceof Node\Identifier) { return null; }
        $property = $context->codebase->getDeclaringProperty($atomic->name, '$'.$read->name->name);
        $physical = $this->physical($node, $read->name->name);
        if ($property === null || $physical === null || ! $this->field($context, $property, $physical, $class->location->file ?? '', $nullable)) { return null; }
        if (! $this->assertion($context)) { return null; }
        $nonNull = array_values(array_filter($getter->returnType->type->atomicTypes, static fn ($part): bool =>
            ! $part instanceof SimpleAtomicType || $part->kind !== SimpleAtomicTypeKind::Null));
        if ($nonNull === [] || count($nonNull) === count($getter->returnType->type->atomicTypes)) { return null; }
        $result = Type::fromAtomics(...$nonNull);
        return $context->types->equals($result, Type::string()) && $context->types->isContainedBy($result, $getter->returnType->type)
            && $this->source->isCurrent() ? $result : null;
    }

    /** @param array<string, mixed> $candidate */
    private function caller(ReturnTypeProviderContext $context, array $candidate): bool
    {
        $scope = $candidate['scope'];
        $owner = $candidate['owner'];
        $method = $owner === null ? $context->codebase->getFunction($candidate['name']) : $context->codebase->getDeclaringMethod($owner, $candidate['name']);
        if ($method === null || strcasecmp($method->identifier->class ?? '', $owner ?? '') !== 0
            || strcasecmp($method->identifier->name, $candidate['name']) !== 0
            || ! self::located($method->location, $scope, $candidate['file'], $this->calls)
            || ! self::located($method->nameLocation, $scope->name, $candidate['file'], $this->calls)
            || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $method->globalsAccessed !== []
            || count($method->parameters) !== count($scope->params)) { return false; }
        foreach ($scope->params as $index => $syntax) {
            $parameter = $method->parameters[$index];
            if ($parameter->name !== '$'.$syntax->var->name || $syntax->byRef || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC) !== $syntax->variadic
                || ! self::located($parameter->nameLocation, $syntax->var, $candidate['file'], $this->calls)) { return false; }
        }
        return true;
    }

    private function classNode(ClassLikeMetadata $metadata, bool $analyzed): ?Node\Stmt\ClassLike
    {
        $file = $metadata->location->file;
        if (! $this->source->isCurrent() || $file === null || $metadata->hasIncompleteHierarchy() || ! $this->calls->sourceMatches($file, $analyzed)) { return null; }
        $nodes = $this->source->read($file) ?? [];
        if ($this->source->contentHash($file) !== @hash_file('sha256', $this->calls->path($file))) { return null; }
        $node = (new NodeFinder)->findFirst($nodes, static fn (Node $node): bool =>
            $node instanceof Node\Stmt\ClassLike && $node->name !== null && isset($node->namespacedName)
            && strcasecmp($node->namespacedName->toString(), $metadata->name) === 0);
        return $node instanceof Node\Stmt\ClassLike && self::located($metadata->location, $node, $file, $this->calls)
            && self::located($metadata->nameLocation, $node->name, $file, $this->calls) ? $node : null;
    }

    private function method(FunctionLikeMetadata $metadata, Node\Stmt\ClassMethod $node, string $file, string $owner): bool
    {
        return $metadata->kind === FunctionLikeKind::Method && $metadata->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            && strcasecmp($metadata->identifier->class ?? '', $owner) === 0 && strcasecmp($metadata->identifier->name, $node->name->name) === 0
            && strcasecmp($metadata->originalName, $node->name->name) === 0
            && self::located($metadata->location, $node, $file, $this->calls)
            && self::located($metadata->nameLocation, $node->name, $file, $this->calls)
            && ! $metadata->flags->contains(MetadataFlags::BY_REFERENCE) && $metadata->static === $node->isStatic()
            && $metadata->abstract === $node->isAbstract() && $metadata->final === $node->isFinal()
            && $metadata->hasDocblock === ($node->getDocComment() !== null)
            && $metadata->visibility === self::visibility($node) && count($metadata->parameters) === count($node->params);
    }

    /** @return array{Node\Stmt\Property|Node\Param, Node, ?Node\Expr}|null */
    private function physical(Node\Stmt\Class_ $class, string $name): ?array
    {
        $found = null;
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->name !== $name) { continue; }
                if ($found !== null || count($property->props) !== 1) { return null; }
                $found = [$property, $item->name, $item->default];
            }
        }
        foreach ($class->getMethod('__construct')?->params ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $name) {
                if ($found !== null) { return null; }
                $found = [$parameter, $parameter->var, $parameter->default];
            }
        }
        return $found;
    }

    /** @param array{Node\Stmt\Property|Node\Param, Node, ?Node\Expr} $physical */
    private function field(ReturnTypeProviderContext $context, PropertyMetadata $metadata, array $physical, string $file, Type $nullable): bool
    {
        [$node, $name, $default] = $physical;
        $promoted = $node instanceof Node\Param;
        $readonly = $node->isReadonly();
        if ($metadata->name !== '$'.$name->name || ! self::nullableString($node->type) || $node->hooks !== [] || $node->attrGroups !== []
            || $node instanceof Node\Stmt\Property && ($node->isStatic() || $node->isAbstract())
            || $node instanceof Node\Param && ($node->byRef || $node->variadic)
            || $metadata->declaredType === null || $metadata->type === null || $metadata->hooks !== []
            || $metadata->declaredType->fromDocblock || $metadata->type->fromDocblock
            || $metadata->flags->contains(MetadataFlags::STATIC) || $metadata->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
            || $metadata->flags->contains(MetadataFlags::WRITEONLY)
            || $metadata->flags->contains(MetadataFlags::PROMOTED_PROPERTY) !== $promoted
            || $metadata->flags->contains(MetadataFlags::READONLY) !== $readonly
            || $metadata->flags->contains(MetadataFlags::ASYMMETRIC_PROPERTY) !== $readonly
            || $metadata->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($default !== null)
            || $metadata->readVisibility !== self::visibility($node)
            || $metadata->writeVisibility !== ($readonly ? Visibility::Protected : self::visibility($node))
            || $metadata->location === null && $promoted
            || $metadata->location !== null && ! self::located($metadata->location, $node, $file, $this->calls)
            || ! self::located($metadata->nameLocation, $name, $file, $this->calls)
            || ! self::located($metadata->declaredType->location, $node->type, $file, $this->calls)
            || ! self::located($metadata->type->location, $node->type, $file, $this->calls)
            || ! $context->types->equals($metadata->declaredType->type, $nullable)
            || ! $context->types->equals($metadata->type->type, $nullable)
            || $metadata->writeType !== null && ! $context->types->equals($metadata->writeType->type, $nullable)) { return false; }
        if ($default === null) { return $metadata->defaultType === null; }
        $value = $default instanceof Node\Scalar\String_ ? Type::literalString($default->value)
            : ($default instanceof Node\Expr\ConstFetch && strtolower($default->name->toString()) === 'null' ? Type::null() : null);
        return $value !== null && $metadata->defaultType !== null && ! $metadata->defaultType->fromDocblock
            && $context->types->equals($metadata->defaultType->type, $value)
            && ($promoted || self::located($metadata->defaultType->location, $default, $file, $this->calls));
    }

    private function assertion(ReturnTypeProviderContext $context): bool
    {
        $codebase = $context->codebase;
        $nodes = [];
        foreach (self::FILES as $name => $suffix) {
            $metadata = $codebase->getClassLike($name);
            $file = $metadata?->location->file ?? '';
            $node = $metadata === null ? null : $this->classNode($metadata, false);
            if (! $node instanceof Node\Stmt\Class_ || ! str_ends_with(strtolower(str_replace('\\', '/', $file)), strtolower($suffix))
                || $metadata->kind !== ClassLikeKind::Class_ || $metadata->usedTraits !== [] || $metadata->templates !== []
                || $metadata->pseudoMethods !== [] || $metadata->staticPseudoMethods !== [] || $metadata->magicProperties !== []
                || $metadata->mixins !== [] || $metadata->directParentClass !== null || $node->extends !== null || $node->getTraitUses() !== []
                || $metadata->attributes !== [] || $node->attrGroups !== []
                || ($name !== self::RECORD && (! $node->isFinal() || ! $metadata->flags->contains(MetadataFlags::FINAL)))) { return false; }
            $nodes[$name] = [$metadata, $node];
        }
        foreach (self::NATIVE_METHODS as $owner => $methods) {
            [$metadata, $class] = $nodes[$owner];
            foreach ($methods as $name => $hash) {
                $method = $codebase->getDeclaringMethod($owner, $name);
                $node = $class->getMethod($name);
                if ($method === null || $node === null || ! $this->method($method, $node, $metadata->location->file ?? '', $owner)
                    || self::fingerprint($node) !== $hash || self::docFingerprint($node) !== (self::NATIVE_DOCS[$owner.'::'.$name] ?? null) || $method->abstract
                    || $method->static !== ($owner !== self::RECORD) || $method->visibility !== Visibility::Public
                    || $method->ifTrueAssertions !== [] || $method->ifFalseAssertions !== [] || $method->assertionsInferred) { return false; }
                foreach ($node->params as $index => $parameter) {
                    $actual = $method->parameters[$index];
                    if ($actual->name !== '$'.$parameter->var->name || $actual->flags->contains(MetadataFlags::BY_REFERENCE)
                        || $actual->flags->contains(MetadataFlags::VARIADIC) || $parameter->byRef || $parameter->variadic
                        || $actual->flags->contains(MetadataFlags::PROMOTED_PROPERTY) !== $parameter->isPromoted()
                        || $actual->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($parameter->default !== null)
                        || ! self::located($actual->nameLocation, $parameter->var, $metadata->location->file ?? '', $this->calls)
                        || $actual->outType !== null || $actual->closureThisType !== null || $actual->attributes !== []
                        || $actual->declaredType === null || ! self::located($actual->declaredType->location, $parameter->type, $metadata->location->file ?? '', $this->calls)) { return false; }
                    if ($parameter->type instanceof Node\Identifier) {
                        $declared = match (strtolower($parameter->type->name)) { 'mixed' => Type::mixed(), 'string' => Type::string(), default => null };
                        if ($declared === null || ! $context->types->equals($actual->declaredType->type, $declared)
                            || $actual->type === null || ! $context->types->isContainedBy($actual->type->type, $declared)) { return false; }
                        $effective = ($owner === self::STATE && $name === 'success' && $index === 1 || $owner === self::RECORD && $index < 2)
                            ? Type::nonEmptyString() : $declared;
                        if (! $context->types->equals($actual->type->type, $effective)) { return false; }
                    }
                    if ($parameter->default === null ? $actual->defaultType !== null
                        : ! $parameter->default instanceof Node\Scalar\String_ || $actual->defaultType === null
                            || $actual->defaultType->fromDocblock || $actual->defaultType->type->getLiteralString() !== $parameter->default->value) { return false; }
                }
            }
        }
        $assert = $codebase->getDeclaringMethod(self::ASSERT, 'notNull');
        if ($assert === null || $assert->returnType === null || $assert->declaredReturnType === null
            || ! $context->types->equals($assert->returnType->type, Type::void())
            || ! $context->types->equals($assert->declaredReturnType->type, Type::void())
            || count($assert->parameters) !== 2 || $assert->templates !== [] || count($assert->assertions) !== 1) { return false; }
        $facts = $assert->assertions['$actual'] ?? $assert->assertions['actual'] ?? [];
        if (count($facts) !== 2) { return false; }
        foreach ($facts as $fact) {
            if (! $fact instanceof TypeAssertion || $fact->kind !== TypeAssertionKind::IsNotType || ! $context->types->equals($fact->type, Type::null())) { return false; }
        }
        $actual = $assert->parameters[0]; $message = $assert->parameters[1];
        if ($actual->declaredType === null || $actual->type === null || $actual->defaultType !== null
            || ! $context->types->equals($actual->declaredType->type, Type::mixed()) || ! $context->types->equals($actual->type->type, Type::mixed())
            || $message->declaredType === null || $message->type === null || $message->defaultType === null
            || ! $context->types->equals($message->declaredType->type, Type::string()) || ! $context->types->equals($message->type->type, Type::string())
            || $message->defaultType->type->getLiteralString() !== '') { return false; }
        $success = $codebase->getDeclaringMethod(self::STATE, 'success');
        $fail = $codebase->getDeclaringMethod(self::STATE, 'fail');
        $stringify = $codebase->getDeclaringMethod(self::SUPPORT, 'stringify');
        if ($success?->returnType === null || ! $context->types->equals($success->returnType->type, Type::void())
            || $fail?->returnType === null || ! $context->types->equals($fail->returnType->type, Type::never())
            || $stringify?->returnType === null || ! $context->types->equals($stringify->returnType->type, Type::nonEmptyString())) { return false; }
        if (count($fail->templates) !== 1 || $fail->templates[0]->name !== 'T' || ! $context->types->equals($fail->templates[0]->constraint, Type::mixed())
            || $fail->templates[0]->default !== null || $fail->parameters[0]->type === null
            || count($fail->parameters[0]->type->type->atomicTypes) !== 1) { return false; }
        $failureType = $fail->parameters[0]->type->type->atomicTypes[0];
        if (! $failureType instanceof GenericParameterType || $failureType->name !== 'T' || ($failureType->intersections ?? []) !== []
            || strcasecmp($failureType->definingEntity->name, self::STATE) !== 0 || strcasecmp($failureType->definingEntity->member ?? '', 'fail') !== 0
            || ! $context->types->equals($failureType->constraint, Type::mixed())) { return false; }
        $constructor = $codebase->getDeclaringMethod(self::RECORD, '__construct');
        if ($constructor === null || count($constructor->parameters) !== 3 || $constructor->templates !== []) { return false; }
        foreach ($constructor->parameters as $parameter) {
            $property = $codebase->getDeclaringProperty(self::RECORD, $parameter->name);
            if ($property === null || $property->name !== $parameter->name || $property->hooks !== [] || $property->declaredType === null || $property->type === null
                || ! $property->flags->contains(MetadataFlags::PROMOTED_PROPERTY) || ! $property->flags->contains(MetadataFlags::READONLY)
                || $property->flags->contains(MetadataFlags::STATIC) || $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
                || $property->readVisibility !== Visibility::Protected || $property->writeVisibility !== Visibility::Protected
                || ! $context->types->equals($property->declaredType->type, Type::string())
                || ! $context->types->equals($property->type->type, $parameter->type->type)) { return false; }
        }
        // The write is an append to an ordinary array on a final collector, never ArrayAccess or a property hook.
        [$collector, $collectorNode] = $nodes[self::COLLECTOR];
        [$state, $stateNode] = $nodes[self::STATE];
        $history = $this->physical($collectorNode, 'history');
        $historyMetadata = $codebase->getDeclaringProperty(self::COLLECTOR, '$history');
        $stateField = $this->physical($stateNode, 'state');
        $stateMetadata = $codebase->getDeclaringProperty(self::STATE, '$state');
        if ($collectorNode->getMethods() !== [] || $collector->methods !== []
            || $history === null || $historyMetadata === null || $historyMetadata->name !== '$history' || $historyMetadata->hooks !== [] || $historyMetadata->readVisibility !== Visibility::Public
            || $historyMetadata->writeVisibility !== Visibility::Public || $historyMetadata->flags->contains(MetadataFlags::STATIC)
            || $historyMetadata->flags->contains(MetadataFlags::VIRTUAL_PROPERTY) || $historyMetadata->flags->contains(MetadataFlags::READONLY)
            || $historyMetadata->flags->contains(MetadataFlags::WRITEONLY) || ! $historyMetadata->flags->contains(MetadataFlags::HAS_DEFAULT)
            || $historyMetadata->declaredType === null || ! $history[0] instanceof Node\Stmt\Property
            || self::fingerprint($history[0]) !== '07198d9e8928af27b8692d384a82d0bde617b7b1443cf625b207dab69b5456cc'
            || self::docFingerprint($history[0]) !== (self::NATIVE_DOCS['history'] ?? null)
            || ! self::located($historyMetadata->nameLocation, $history[1], $collector->location->file ?? '', $this->calls)
            || ! self::located($historyMetadata->declaredType->location, $history[0]->type, $collector->location->file ?? '', $this->calls)
            || ! $context->types->equals($historyMetadata->declaredType->type, Type::array(Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey)), Type::mixed()))
            || $historyMetadata->type === null || ! $context->types->equals($historyMetadata->type->type, Type::list(Type::namedObject('Testo\\Assert\\State\\Record')))
            || $historyMetadata->defaultType === null || ! $context->types->equals($historyMetadata->defaultType->type, Type::fromAtomic(new ListType(Type::never(), [], 0, false)))
            || $stateField === null || $stateMetadata === null || $stateMetadata->name !== '$state' || $stateMetadata->hooks !== []
            || $stateMetadata->readVisibility !== Visibility::Public || $stateMetadata->writeVisibility !== Visibility::Public
            || ! $stateMetadata->flags->contains(MetadataFlags::STATIC) || $stateMetadata->declaredType === null
            || ! $stateField[0] instanceof Node\Stmt\Property || self::fingerprint($stateField[0]) !== 'eaa0deb481559542d7e115c981cedb7bada39781d276995601566a66cada288c'
            || ! self::located($stateMetadata->nameLocation, $stateField[1], $state->location->file ?? '', $this->calls)
            || ! self::located($stateMetadata->declaredType->location, $stateField[0]->type, $state->location->file ?? '', $this->calls)
            || $stateMetadata->type === null || $stateMetadata->defaultType === null
            || ! $context->types->equals($stateMetadata->defaultType->type, Type::null())
            || ! $context->types->equals($stateMetadata->type->type, $stateMetadata->declaredType->type)
            || ! $context->types->equals($stateMetadata->declaredType->type, Type::union(Type::namedObject(self::COLLECTOR), Type::null()))) { return false; }
        foreach (['is_string', 'strlen', 'str_replace', 'is_array', 'count', 'is_resource', 'is_object'] as $name) {
            if (! ($codebase->getFunction($name)?->flags->contains(MetadataFlags::BUILTIN) ?? false)) { return false; }
        }
        return true;
    }

    private static function nullableString(?Node $node): bool
    {
        return $node instanceof Node\NullableType && $node->type instanceof Node\Identifier && strtolower($node->type->name) === 'string';
    }

    private static function visibility(Node\Stmt\ClassMethod|Node\Stmt\Property|Node\Param $node): Visibility
    {
        return $node->isPrivate() ? Visibility::Private : ($node->isProtected() ? Visibility::Protected : Visibility::Public);
    }

    private static function located(?SourceLocation $location, Node $node, string $file, AssertedPureGetterCalls $calls): bool
    {
        if ($location === null || $calls->path($location->file ?? '') !== $calls->path($file) || $location->span->end !== $node->getEndFilePos() + 1) { return false; }
        if ($location->span->start === $node->getStartFilePos()) { return true; }
        foreach ($node->getComments() as $comment) { if ($location->span->start === $comment->getStartFilePos()) { return true; } }
        return false;
    }

    public static function fingerprint(Node $node): string
    {
        $text = (new Standard)->prettyPrint([$node]);
        $parts = [];
        foreach (token_get_all('<?php '.$text) as $token) {
            if (! is_array($token)) { $parts[] = $token; }
            elseif (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $parts[] = $token[1]; }
        }
        return hash('sha256', implode('', $parts));
    }

    public static function docFingerprint(Node $node): string
    {
        return hash('sha256', preg_replace('/\s+/', ' ', $node->getDocComment()?->getText() ?? '') ?? '');
    }
}
