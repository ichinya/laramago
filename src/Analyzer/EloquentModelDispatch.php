<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Shared contracts for model calls forwarded to the standard Eloquent builder. */
final class EloquentModelDispatch
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    public function modelType(Codebase $codebase, Invocation $call): ?Type
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
            if ($this->overrides($codebase, $atom->name, $name)) {
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
            foreach ([...($class->pseudoMethods ?? []), ...($class->staticPseudoMethods ?? [])] as $method) {
                if (strcasecmp($method, $call->name) === 0) {
                    return null;
                }
            }
            foreach ($class->attributes ?? [] as $attribute) {
                if (
                    strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder') === 0
                ) {
                    return null;
                }
            }
        }

        // A forwarded operation does not return the receiver's existing property refinements.
        return Type::fromAtomic(new NamedObjectType(
            $atom->name,
            $atom->parameters,
            $atom->variances,
            $atom->static,
            false,
            null,
            $atom->remappedParameters,
        ));
    }

    public function signature(Codebase $codebase, string $name): ?EffectiveCallableSignature
    {
        $method = $this->method($codebase, self::BUILDER, $name);
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

        return new EffectiveCallableSignature($parameters, displayName: self::BUILDER.'::'.$method->originalName);
    }

    public function overrides(Codebase $codebase, string $class, string $name, ?string $trait = null): bool
    {
        $method = $this->method($codebase, $class, $name);

        return (
            $method !== null
            && strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0
            && ($trait === null || strcasecmp($method->identifier->class ?? '', $trait) !== 0)
        );
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
