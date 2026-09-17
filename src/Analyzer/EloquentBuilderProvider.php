<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Specializes native query entry points while retaining native argument checks. */
final class EloquentBuilderProvider implements MethodReturnTypeProvider
{
    public function getTargets(): array
    {
        return array_map(
            static fn (string $name): MethodTarget => MethodTarget::exact(EloquentBuilderType::MODEL, $name),
            ['query', 'newQuery', 'newModelQuery', 'newQueryWithoutScopes', 'newQueryWithoutRelationships'],
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $atom = $receiver?->atomicTypes[0] ?? null;
        if ($receiver === null || count($receiver->atomicTypes) !== 1 || ! $atom instanceof NamedObjectType) {
            return null;
        }
        $method = $context->codebase->getMethod($atom->name, $call->name) ?? $context->codebase->getDeclaringMethod(
            $atom->name,
            $call->name,
        );
        if ($method === null || strcasecmp($method->identifier->class ?? '', EloquentBuilderType::MODEL) !== 0) {
            return null;
        }
        foreach ($context->codebase->getMultipleClasses([
            $atom->name,
            ...$context->codebase->getClassAncestors($atom->name),
        ]) as $class) {
            foreach ([...($class->pseudoMethods ?? []), ...($class->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, $call->name) === 0) {
                    return null;
                }
            }
        }

        return (new EloquentBuilderType)->resolve($context->codebase, $atom->name);
    }
}
