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
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\VariableType;

/** Exposes real service methods through statically provable Laravel facades. */
final class FacadeCallProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private readonly FacadeCallResolver $resolver;

    public function __construct(string $projectRoot = '.')
    {
        $this->resolver = new FacadeCallResolver($projectRoot);
    }

    public function getTargets(): array
    {
        return [MethodTarget::allMethods(FacadeCallResolver::FACADE)];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = $this->resolver->forwardedMethod($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$method] = $resolved;
        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $type = $parameter->type->type ?? $parameter->declaredType?->type;
            $closureThis = $parameter->closureThisType?->type;
            if ($type !== null && self::unresolved($type) || $closureThis !== null && self::unresolved($closureThis)) {
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

        return new EffectiveCallableSignature(
            $parameters,
            displayName: ($method->identifier->class ?? $resolved[1]).'::'.$method->originalName,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = $this->resolver->forwardedMethod($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$method, $root] = $resolved;
        $return = $method->returnType->type ?? $method->declaredReturnType?->type;
        $declared = $method->declaredReturnType?->type;
        if ($return !== null && $declared !== null && ! $context->types->isContainedBy($return, $declared)) {
            $return = $declared;
        }
        if ($return === null) {
            return null;
        }
        $result = null;
        foreach ($return->atomicTypes as $atom) {
            if ($atom instanceof NamedObjectType && ($atom->static || $atom->isThis)) {
                if (($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== []) {
                    return null;
                }
                $type = Type::namedObject($root);
            } else {
                if (self::unresolved($atom)) {
                    return null;
                }
                $type = Type::fromAtomic($atom);
            }
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }

    /** Never export unresolved templates or contextual types inside nested contracts. */
    private static function unresolved(mixed $value): bool
    {
        if (
            $value instanceof GenericParameterType
            || $value instanceof ConditionalType
            || $value instanceof AliasType
            || $value instanceof VariableType
        ) {
            return true;
        }
        if ($value instanceof NamedObjectType && ($value->static || $value->isThis)) {
            return true;
        }
        if ($value instanceof Type) {
            $value = $value->atomicTypes;
        } elseif ($value instanceof AtomicType) {
            $value = get_object_vars($value);
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) && array_filter($value, self::unresolved(...)) !== [];
    }
}
