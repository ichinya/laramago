<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\UndeclaredReturnTypeProvider;

/** Resolve the initial family of magic Eloquent query methods. */
final class EloquentWhereProvider implements
    MethodReturnTypeProvider,
    CallableSignatureProvider,
    UndeclaredReturnTypeProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    public function getTargets(): array
    {
        return array_map(
            static fn (string $method): MethodTarget => MethodTarget::exact(self::MODEL, $method),
            ['where', 'orWhere', 'whereNot', 'orWhereNot'],
        );
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $builder = $this->builderType($context->codebase, $context->invocation);
        $method = $context->codebase->getMethod(self::BUILDER, $context->invocation->name);
        if ($builder === null || $method === null) {
            return null;
        }

        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $this->bindBuilder($parameter->type?->type ?? $parameter->declaredType?->type, $builder),
                closureThisType: $this->bindBuilder($parameter->closureThisType?->type, $builder),
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }

        return new EffectiveCallableSignature($parameters, displayName: self::BUILDER.'::'.$method->originalName);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        if ($context->codebase->getMethod(self::BUILDER, $context->invocation->name) === null) {
            return null;
        }

        return $this->builderType($context->codebase, $context->invocation);
    }

    private function builderType(Codebase $codebase, Invocation $invocation): ?Type
    {
        $receiver = $invocation->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
        ) {
            return null;
        }
        $model = $receiver->atomicTypes[0]->name;
        // A custom query factory or magic dispatcher needs its own type provider.
        foreach ([
            '__call',
            '__callStatic',
            'newQuery',
            'newModelQuery',
            'newQueryWithoutScopes',
            'newQueryWithoutRelationships',
            'newEloquentBuilder',
            'resolveCustomBuilderClass',
        ] as $name) {
            $method = $codebase->getMethod($model, $name);
            if ($method !== null && strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0) {
                return null;
            }
        }
        $builderProperty = $codebase->getProperty($model, '$builder');
        if (
            $builderProperty !== null
            && strcasecmp($builderProperty->defaultType?->type->getLiteralClassString() ?? '', self::BUILDER) !== 0
        ) {
            return null;
        }
        foreach ($codebase->getMultipleClasses([$model, ...$codebase->getClassAncestors($model)]) as $class) {
            foreach ($class?->attributes ?? [] as $attribute) {
                if (
                    strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder') === 0
                ) {
                    return null;
                }
            }
        }

        return Type::namedObject(self::BUILDER, $receiver);
    }

    /** Bind the where callback's static Builder type to the calling model. */
    private function bindBuilder(?Type $type, Type $builder): ?Type
    {
        if ($type === null) {
            return null;
        }
        $atoms = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof NamedObjectType && strcasecmp($atom->name, self::BUILDER) === 0) {
                array_push($atoms, ...$builder->atomicTypes);
                continue;
            }
            if ($atom instanceof CallableType && $atom->signature !== null) {
                $signature = $atom->signature;
                $parameters = array_map(fn (CallableParameter $parameter): CallableParameter => new CallableParameter(
                    name: $parameter->name,
                    type: $this->bindBuilder($parameter->type, $builder),
                    closureThisType: $this->bindBuilder($parameter->closureThisType, $builder),
                    byReference: $parameter->byReference,
                    variadic: $parameter->variadic,
                    hasDefault: $parameter->hasDefault,
                ), $signature->parameters);
                $atom = new CallableType(new CallableSignature(
                    $signature->pure,
                    $signature->closure,
                    $parameters,
                    $this->bindBuilder($signature->returnType, $builder),
                    $signature->source,
                    $signature->constraints,
                ), null);
            }
            $atoms[] = $atom;
        }

        return Type::fromAtomics(...$atoms)->withFlags($type->flags);
    }
}
