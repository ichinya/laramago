<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Copies real public static Str contracts onto the installed anonymous proxy. */
final class StringProxyProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    public function __construct(
        private readonly StringHelperProvider $helper,
    ) {}

    public function getTargets(): array
    {
        return [MethodTarget::allMethods('{anonymous-class:*')];
    }

    private function method(Codebase $codebase, Invocation $call): ?FunctionLikeMetadata
    {
        $atom = $call->receiverType?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || count($call->receiverType?->atomicTypes ?? []) !== 1
            || ! $atom instanceof NamedObjectType
            || $atom->name !== $this->helper->proxy($codebase)
            || $codebase->getMethod($atom->name, $call->name) !== null
        ) {
            return null;
        }
        $method = $codebase->getMethod('Illuminate\\Support\\Str', $call->name);

        return $method !== null
        && $method->static
        && $method->visibility === Visibility::Public
        && $method->templates === []
        && ! $method->flags->contains(MetadataFlags::BY_REFERENCE)
        && ! self::unresolved($method->returnType?->type ?? $method->declaredReturnType?->type)
        && array_filter(
            $method->parameters,
            static fn ($parameter): bool => (
                $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || self::unresolved($parameter->type?->type ?? $parameter->declaredType?->type)
                || self::unresolved($parameter->closureThisType?->type)
            ),
        ) === []
            ? $method
            : null;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $method = $this->method($context->codebase, $context->invocation);
        if ($method === null) {
            return null;
        }
        $parameters = [];
        foreach ($method->parameters as $parameter) {
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
            displayName: 'Illuminate\\Support\\Str::'.$method->originalName,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $method = $this->method($context->codebase, $context->invocation);

        $return = $method?->returnType?->type ?? $method?->declaredReturnType?->type;
        $declared = $method?->declaredReturnType?->type;

        return $return !== null && $declared !== null && ! $context->types->isContainedBy($return, $declared)
            ? $declared
            : $return;
    }

    /** Never export unresolved templates or contextual types inside nested contracts. */
    private static function unresolved(mixed $value): bool
    {
        if (
            $value instanceof \Mago\Sdk\Analyzer\Type\GenericParameterType
            || $value instanceof \Mago\Sdk\Analyzer\Type\ConditionalType
            || $value instanceof \Mago\Sdk\Analyzer\Type\AliasType
            || $value instanceof \Mago\Sdk\Analyzer\Type\VariableType
        ) {
            return true;
        }
        if ($value instanceof NamedObjectType && ($value->static || $value->isThis)) {
            return true;
        }
        if ($value instanceof Type) {
            $value = $value->atomicTypes;
        } elseif ($value instanceof \Mago\Sdk\Analyzer\Type\AtomicType) {
            $value = get_object_vars($value);
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) && array_filter($value, self::unresolved(...)) !== [];
    }
}
