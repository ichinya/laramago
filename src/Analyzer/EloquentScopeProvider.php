<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;

/** Reads local scope contracts without invoking models or scope bodies. */
final class EloquentScopeProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    public function getTargets(): array
    {
        return [
            MethodTarget::allMethods(self::MODEL),
            MethodTarget::allMethods(self::BUILDER),
            ...array_map(
                static fn (string $method): MethodTarget => MethodTarget::exact(
                    'Illuminate\\Database\\Query\\Builder',
                    $method,
                ),
                EloquentQueryProvider::predicateMethods(),
            ),
        ];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = (new EloquentScopeResolver)->resolve($context->codebase, $context->invocation, $context->types);
        if ($resolved === null) {
            return null;
        }
        [$method] = $resolved;
        $parameters = [];
        foreach (array_slice($method->parameters, 1) as $parameter) {
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $parameter->type->type ?? $parameter->declaredType?->type,
                closureThisType: $parameter->closureThisType?->type,
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }

        return new EffectiveCallableSignature(
            $parameters,
            displayName: ($method->identifier->class ?? self::MODEL).'::'.$method->originalName,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = (new EloquentScopeResolver)->resolve($context->codebase, $context->invocation, $context->types);
        if ($resolved === null) {
            return null;
        }
        [$method, $model] = $resolved;
        $return = $method->returnType->type ?? $method->declaredReturnType?->type;
        if ($return === null) {
            return null;
        }
        $builder = Type::namedObject(self::BUILDER, $model);
        $result = null;
        foreach ($return->atomicTypes as $atom) {
            $type = Type::fromAtomic($atom);
            // callScope uses the current builder only when the scope returns null.
            $type = in_array((string) $type, ['void', 'null'], true) ? $builder : $type;
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }
}
