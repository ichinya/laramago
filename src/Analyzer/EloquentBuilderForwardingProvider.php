<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Exposes explicitly declared custom builder methods through model dispatch. */
final class EloquentBuilderForwardingProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::allMethods(EloquentBuilderType::MODEL)];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = $this->resolve($context->codebase, $context->invocation);

        return $resolved === null
            ? null
            : (new EloquentModelDispatch)->signature(
                $context->codebase,
                $context->invocation->name,
                $resolved[1],
            );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = $this->resolve($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$method, $builder] = $resolved;
        $return = $method->returnType->type ?? $method->declaredReturnType?->type;
        $declared = $method->declaredReturnType?->type;
        // Inherited PHPDoc may still describe the parent's implementation.
        // It cannot replace an incompatible concrete override contract.
        if ($return !== null && $declared !== null && ! $context->types->isContainedBy($return, $declared)) {
            $return = $declared;
        }
        if ($return === null) {
            return null;
        }
        $result = null;
        foreach ($return->atomicTypes as $atom) {
            // Late-static returns belong to the builder, not the model receiver.
            $type =
                $atom instanceof NamedObjectType && ($atom->static || $atom->isThis)
                    ? Type::namedObject($builder)
                    : Type::fromAtomic($atom);
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }

    /** @return null|array{FunctionLikeMetadata, string} */
    private function resolve(Codebase $codebase, Invocation $call): ?array
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
        if (! $builderAtom instanceof NamedObjectType || ($builderAtom->parameters ?? []) !== []) {
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
            || $method->templates !== []
            || strcasecmp($method->identifier->class ?? '', EloquentBuilderType::BUILDER) === 0
        ) {
            return null;
        }
        // Generic declaring ancestors require template substitution, handled separately.
        $declaring = $method->identifier->class;
        if ($declaring === null || ($codebase->getClassLike($declaring)?->templates ?? []) !== []) {
            return null;
        }

        return [$method, $builderAtom->name];
    }
}
