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

/** Selects the scalar or collection branch of Eloquent primary-key lookups. */
final class EloquentFindProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';
    private const ARRAYABLE = 'Illuminate\\Contracts\\Support\\Arrayable';

    public function getTargets(): array
    {
        $targets = [];
        foreach (['find', 'findOrFail', 'findOrNew', 'findMany', 'findSole'] as $method) {
            $targets[] = MethodTarget::exact(self::MODEL, $method);
            $targets[] = MethodTarget::exact(self::BUILDER, $method);
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $receiver = $context->invocation->receiverType?->atomicTypes[0] ?? null;
        if (
            ! $receiver instanceof NamedObjectType
            || ! $this->inherits($context->codebase, $receiver->name, self::MODEL)
            || $this->modelType($context->codebase, $context->invocation) === null
        ) {
            return null;
        }
        $method = $this->method($context->codebase, self::BUILDER, $context->invocation->name);
        if ($method === null) {
            return null;
        }
        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $parameter->type?->type ?? $parameter->declaredType?->type,
                closureThisType: $parameter->closureThisType?->type,
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }

        return new EffectiveCallableSignature($parameters, displayName: self::BUILDER.'::'.$method->originalName);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $model = $this->modelType($context->codebase, $call);
        if ($model === null) {
            return null;
        }
        $name = strtolower($call->name);
        $collection = Type::namedObject(self::COLLECTION, Type::int(), $model);
        if ($name === 'findmany') {
            return $collection;
        }
        if ($name === 'findsole') {
            return $model;
        }
        $single = $name === 'find' ? Type::union($model, Type::null()) : $model;
        $id = $call->getArgument(0, 'id');
        // The type of an unpacked argument describes the argument list, not the id.
        foreach ($call->arguments as $argument) {
            if ($argument->placeholder) {
                return null;
            }
            if ($argument->unpacked) {
                return Type::union($single, $collection);
            }
        }
        $idType = $id?->type;
        if ($idType === null) {
            return Type::union($single, $collection);
        }
        $multipleIds = Type::union(
            Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
            Type::namedObject(self::ARRAYABLE),
        );
        if ($context->types->isContainedBy($idType, $multipleIds)) {
            return $collection;
        }
        if (! $context->types->canBeIdentical($idType, $multipleIds)) {
            return $single;
        }

        return Type::union($single, $collection);
    }

    private function modelType(Codebase $codebase, Invocation $call): ?Type
    {
        $receiver = $call->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
            || $this->method($codebase, self::BUILDER, $call->name) === null
        ) {
            return null;
        }
        $atom = $receiver->atomicTypes[0];
        if (strcasecmp($atom->name, self::BUILDER) === 0) {
            $model = $atom->parameters[0] ?? null;

            return $model !== null && $this->standardCollections($codebase, $model) ? $model : null;
        }
        if (
            ! $this->inherits($codebase, $atom->name, self::MODEL)
            || $this->method($codebase, $atom->name, $call->name) !== null
        ) {
            return null;
        }
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
            $method = $this->method($codebase, $atom->name, $name);
            if ($method !== null && strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0) {
                return null;
            }
        }
        $builder = $codebase->getDeclaringProperty($atom->name, '$builder') ?? $codebase->getProperty(
            $atom->name,
            '$builder',
        );
        if (
            $builder !== null
            && strcasecmp($builder->defaultType?->type->getLiteralClassString() ?? '', self::BUILDER) !== 0
        ) {
            return null;
        }
        foreach ($codebase->getMultipleClasses([$atom->name, ...$codebase->getClassAncestors($atom->name)]) as $class) {
            foreach ([...($class?->pseudoMethods ?? []), ...($class?->staticPseudoMethods ?? [])] as $method) {
                if (strcasecmp($method, $call->name) === 0) {
                    return null;
                }
            }
            foreach ($class?->attributes ?? [] as $attribute) {
                if (
                    strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder') === 0
                ) {
                    return null;
                }
            }
        }

        // A lookup returns a fresh model, not the receiver's identity or property refinements.
        $model = Type::fromAtomic(new NamedObjectType(
            $atom->name,
            $atom->parameters,
            $atom->variances,
            $atom->static,
            false,
            null,
            $atom->remappedParameters,
        ));

        return $this->standardCollections($codebase, $model) ? $model : null;
    }

    /** A custom collection contract must remain under native analysis. */
    private function standardCollections(Codebase $codebase, Type $model): bool
    {
        foreach ($model->atomicTypes as $atom) {
            if (! $atom instanceof NamedObjectType || ! $this->inherits($codebase, $atom->name, self::MODEL)) {
                return false;
            }
            foreach (['newCollection', 'resolveCollectionFromAttribute'] as $name) {
                $method = $this->method($codebase, $atom->name, $name);
                if (
                    $method !== null
                    && strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0
                    && strcasecmp($method->identifier->class ?? '', 'Illuminate\\Database\\Eloquent\\HasCollection')
                        !== 0
                ) {
                    return false;
                }
            }
            $collection = $codebase->getDeclaringProperty($atom->name, '$collectionClass') ?? $codebase->getProperty(
                $atom->name,
                '$collectionClass',
            );
            if (
                $collection !== null
                && strcasecmp($collection->defaultType?->type->getLiteralClassString() ?? '', self::COLLECTION) !== 0
            ) {
                return false;
            }
            foreach ($codebase->getMultipleClasses([
                $atom->name,
                ...$codebase->getClassAncestors($atom->name),
            ]) as $class) {
                foreach ($class?->attributes ?? [] as $attribute) {
                    if (strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\CollectedBy') === 0) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function method(Codebase $codebase, string $class, string $method): ?FunctionLikeMetadata
    {
        return $codebase->getMethod($class, $method) ?? $codebase->getDeclaringMethod($class, $method);
    }

    private function inherits(Codebase $codebase, string $class, string $parent): bool
    {
        return (
            strcasecmp($class, $parent) === 0
            || in_array(strtolower($parent), array_map(strtolower(...), $codebase->getClassAncestors($class)), true)
        );
    }
}
