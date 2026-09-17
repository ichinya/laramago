<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;

/** Exposes explicitly declared custom builder methods through model dispatch. */
final class EloquentBuilderForwardingProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::allMethods(EloquentBuilderType::MODEL)];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = $this->resolve($context->codebase, $context->invocation, $context->types);

        if ($resolved === null) {
            return null;
        }
        [$method, $builder, $generics] = $resolved;
        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $original = $parameter->type->type ?? $parameter->declaredType?->type;
            $type = $original === null ? null : $generics->substitute($original);
            $native = $parameter->declaredType?->type;
            if ($original !== null && $type === null) {
                return null;
            }
            if ($type !== null && $native !== null && ! $context->types->isContainedBy($type, $native)) {
                $type = $native;
            }
            $closureThis = $parameter->closureThisType?->type;
            if ($closureThis !== null && ($closureThis = $generics->substitute($closureThis)) === null) {
                return null;
            }
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $type,
                closureThisType: $closureThis,
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }

        return new EffectiveCallableSignature($parameters, displayName: $builder->name.'::'.$method->originalName);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = $this->resolve($context->codebase, $context->invocation, $context->types);
        if ($resolved === null) {
            return null;
        }
        [$method, , $generics] = $resolved;
        $return = $method->returnType->type ?? $method->declaredReturnType?->type;
        $return = $return === null ? null : $generics->substitute($return);
        $declared = $method->declaredReturnType?->type;
        $declared = $declared === null ? null : $generics->substitute($declared);
        $return ??= $declared;
        // Inherited PHPDoc may still describe the parent's implementation.
        // It cannot replace an incompatible concrete override contract.
        if ($return !== null && $declared !== null && ! $context->types->isContainedBy($return, $declared)) {
            $return = $declared;
        }

        return $return;
    }

    /** @return null|array{FunctionLikeMetadata, NamedObjectType, EloquentBuilderGenericTypes} */
    private function resolve(Codebase $codebase, Invocation $call, TypeComparator $types): ?array
    {
        $receiver = $call->receiverType;
        $atom = $receiver?->atomicTypes[0] ?? null;
        if ($receiver === null || count($receiver->atomicTypes) !== 1 || ! $atom instanceof NamedObjectType) {
            return null;
        }
        $dispatch = new EloquentModelDispatch;
        if (
            $codebase->getMethod($atom->name, $call->name) !== null
            || $codebase->getDeclaringMethod($atom->name, $call->name) !== null
            || $dispatch->overrides($codebase, $atom->name, '__call')
            || $dispatch->overrides($codebase, $atom->name, '__callStatic')
        ) {
            return null;
        }
        foreach ($codebase->getMultipleClasses([$atom->name, ...$codebase->getClassAncestors($atom->name)]) as $class) {
            foreach ([...($class->pseudoMethods ?? []), ...($class->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, $call->name) === 0) {
                    return null;
                }
            }
        }
        $builder = (new EloquentBuilderType)->resolve($codebase, $atom->name);
        $builderAtom = $builder?->atomicTypes[0] ?? null;
        if (! $builderAtom instanceof NamedObjectType) {
            return null;
        }
        $method = $codebase->getMethod($builderAtom->name, $call->name) ?? $codebase->getDeclaringMethod(
            $builderAtom->name,
            $call->name,
        );
        if (
            $method === null
            || $method->visibility !== Visibility::Public
            || $method->static
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || $method->templates !== []
            || strcasecmp($method->identifier->class ?? '', EloquentBuilderType::BUILDER) === 0
        ) {
            return null;
        }
        // The SDK exposes template declarations, but not ancestor argument mappings.
        $declaring = $method->identifier->class;
        if (
            $declaring === null
            || strcasecmp($declaring, $builderAtom->name) !== 0
            && ($codebase->getClassLike($declaring)?->templates ?? []) !== []
        ) {
            return null;
        }
        $bindings = [];
        foreach ($codebase->getClassLike($builderAtom->name)?->templates ?? [] as $index => $template) {
            $binding = $builderAtom->parameters[$index] ?? null;
            if ($binding === null) {
                return null;
            }
            $bindings[$template->name] = $binding;
        }

        $generics = new EloquentBuilderGenericTypes($builderAtom, $bindings);
        foreach ($codebase->getClassLike($builderAtom->name)?->templates ?? [] as $template) {
            $constraint = $generics->substitute($template->constraint);
            if ($constraint === null || ! $types->isContainedBy($bindings[$template->name], $constraint)) {
                return null;
            }
        }
        // Both provider phases must agree before recognizing a dynamic method.
        foreach ($method->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return null;
            }
            $type = $parameter->type->type ?? $parameter->declaredType?->type;
            if ($type !== null && $generics->substitute($type) === null) {
                return null;
            }
            $closureThis = $parameter->closureThisType?->type;
            if ($closureThis !== null && $generics->substitute($closureThis) === null) {
                return null;
            }
        }

        return [$method, $builderAtom, $generics];
    }
}
