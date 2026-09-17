<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;

/** Resolves only facade calls backed by a statically provable class contract. */
final class FacadeCallResolver
{
    public const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    private readonly PhpSource $source;
    private readonly ContainerBindings $bindings;

    public function __construct(string $projectRoot)
    {
        $this->source = new PhpSource($projectRoot);
        $this->bindings = new ContainerBindings($projectRoot);
    }

    public function rootClass(Codebase $codebase, Type $receiver): ?string
    {
        $object = $receiver->atomicTypes[0];
        if (count($receiver->atomicTypes) !== 1 || ! $object instanceof NamedObjectType) {
            return null;
        }
        foreach (['getFacadeRoot', 'resolveFacadeInstance'] as $name) {
            $method = $codebase->getDeclaringMethod($object->name, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::FACADE) !== 0) {
                return null;
            }
        }
        $accessor = $codebase->getDeclaringMethod($object->name, 'getFacadeAccessor');
        if ($accessor === null) {
            return null;
        }
        $expression = (new ModelReflection($codebase, $this->source))->returnExpression($accessor);
        if (! $expression instanceof Node\Expr\ClassConstFetch && ! $expression instanceof Node\Scalar\String_) {
            return null;
        }
        $name = PhpSource::value($expression, $accessor->identifier->class, $object->name);
        if (! is_string($name)) {
            return null;
        }

        if ($this->bindings->configured($name)) {
            return $this->bindings->concrete($name, $codebase);
        }
        if ($expression instanceof Node\Scalar\String_) {
            return null;
        }
        if ($codebase->getClassLike($name) === null) {
            return null;
        }

        return $name;
    }

    /** @return null|array{FunctionLikeMetadata, string} */
    public function forwardedMethod(Codebase $codebase, Invocation $call): ?array
    {
        if ($call->kind !== InvocationKind::StaticMethod || $call->receiverType === null) {
            return null;
        }
        $receiver = $call->receiverType->atomicTypes[0];
        if (count($call->receiverType->atomicTypes) !== 1 || ! $receiver instanceof NamedObjectType) {
            return null;
        }
        if (
            $codebase->getMethod($receiver->name, $call->name) !== null
            || $codebase->getDeclaringMethod($receiver->name, $call->name) !== null
        ) {
            return null;
        }
        foreach ($codebase->getMultipleClasses([
            $receiver->name,
            ...$codebase->getClassAncestors($receiver->name),
        ]) as $class) {
            if ($class === null) {
                return null;
            }
            foreach ([...$class->pseudoMethods, ...$class->staticPseudoMethods] as $name) {
                if (strcasecmp($name, $call->name) === 0) {
                    return null;
                }
            }
        }
        $dispatcher = $codebase->getDeclaringMethod($receiver->name, '__callStatic');
        if ($dispatcher === null || strcasecmp($dispatcher->identifier->class ?? '', self::FACADE) !== 0) {
            return null;
        }
        $root = $this->rootClass($codebase, $call->receiverType);
        if ($root === null || ($codebase->getClassLike($root)?->templates ?? []) !== []) {
            return null;
        }
        $method = $codebase->getMethod($root, $call->name) ?? $codebase->getDeclaringMethod($root, $call->name);
        if (
            $method === null
            || $method->visibility !== Visibility::Public
            || $method->static
            || $method->templates !== []
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
        ) {
            return null;
        }
        foreach ($method->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return null;
            }
        }
        $declaring = $method->identifier->class;
        if ($declaring === null || ($codebase->getClassLike($declaring)?->templates ?? []) !== []) {
            return null;
        }

        return [$method, $root];
    }
}
