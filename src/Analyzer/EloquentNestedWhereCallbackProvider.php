<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Bind a nested relation where closure to its related Eloquent builder. */
final class EloquentNestedWhereCallbackProvider implements CallableSignatureOverride, MethodReturnTypeProvider
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';
    private const RELATION = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';
    private const RELATIONS = [
        'HasMany', 'HasOne', 'BelongsTo', 'BelongsToMany', 'HasManyThrough',
        'HasOneThrough', 'MorphMany', 'MorphOne', 'MorphToMany', 'MorphedByMany',
    ];
    private const METHODS = ['where', 'orwhere'];

    public function getTargets(): array
    {
        $targets = [];
        foreach ([self::BUILDER, self::QUERY] as $class) {
            foreach (self::METHODS as $method) {
                $targets[] = MethodTarget::exact($class, $method);
            }
        }
        foreach (self::RELATIONS as $relation) {
            foreach (self::METHODS as $method) {
                $targets[] = MethodTarget::exact('Illuminate\\Database\\Eloquent\\Relations\\'.$relation, $method);
            }
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || ! in_array(strtolower($call->name), self::METHODS, true)
            || count($call->receiverType?->atomicTypes ?? []) !== 1
            || ! $receiver instanceof NamedObjectType
            || count($call->arguments) !== 1
            || $call->arguments[0]->unpacked
            || $call->arguments[0]->placeholder
            || ! in_array($call->arguments[0]->name, [null, 'column'], true)
            || preg_match('/^(?:static\s+)?(?:function\s*\(|fn\s*\()/', ltrim($call->arguments[0]->expression)) !== 1
        ) {
            return null;
        }
        $related = $this->relatedModel($context->codebase, $receiver, $call);
        if ($related === null) {
            return null;
        }
        $method = $context->codebase->getMethod(self::BUILDER, $call->name)
            ?? $context->codebase->getDeclaringMethod(self::BUILDER, $call->name);
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::BUILDER) !== 0
            || $method->visibility !== Visibility::Public
            || $method->static
            || count($method->parameters) !== (strcasecmp($call->name, 'where') === 0 ? 4 : 3)
            || $method->parameters[0]->name !== '$column'
            || ! str_ends_with(
                '/'.ltrim(str_replace('\\', '/', $method->location->file ?? ''), '/'),
                '/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php',
            )
        ) {
            return null;
        }
        $native = (new EloquentModelDispatch)->signature($context->codebase, $call->name, self::BUILDER);
        $column = $native?->parameters[0]->type;
        if ($native === null || $column === null) {
            return null;
        }
        $builder = Type::namedObject(self::BUILDER, $related);
        $replaced = [];
        $callbacks = 0;
        foreach ($column->atomicTypes as $atom) {
            if (! $atom instanceof CallableType) {
                $replaced[] = $atom;
                continue;
            }
            $signature = $atom->signature;
            $parameter = $signature?->parameters[0] ?? null;
            $query = $parameter?->type?->atomicTypes[0] ?? null;
            if (
                ! $signature?->closure
                || count($signature->parameters) !== 1
                || ! $query instanceof NamedObjectType
                || strcasecmp($query->name, self::BUILDER) !== 0
                || count($parameter->type->atomicTypes) !== 1
            ) {
                return null;
            }
            $callbacks++;
            $replaced[] = new CallableType(new CallableSignature(
                $signature->pure,
                $signature->closure,
                [new CallableParameter(
                    $parameter->name,
                    $builder,
                    $parameter->closureThisType,
                    $parameter->byReference,
                    $parameter->variadic,
                    $parameter->hasDefault,
                )],
                $signature->returnType,
                $signature->source,
                $signature->constraints,
            ), null);
        }
        if ($callbacks !== 1) {
            return null;
        }
        $parameters = [];
        foreach ($native->parameters as $index => $parameter) {
            $parameters[] = new CallableParameter(
                $parameter->name,
                $index === 0 ? Type::fromAtomics(...$replaced)->withFlags($column->flags) : $parameter->type,
                $parameter->closureThisType,
                $parameter->byReference,
                $parameter->variadic,
                $parameter->hasDefault,
            );
        }

        return new EffectiveCallableSignature(
            $parameters,
            $native->allowsNamedArguments,
            $native->displayName,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return null;
    }

    private function relatedModel(Codebase $codebase, NamedObjectType $receiver, Invocation $call): ?Type
    {
        $class = $receiver->name;
        if (! in_array($class, array_map(
            static fn (string $relation): string => 'Illuminate\\Database\\Eloquent\\Relations\\'.$relation,
            self::RELATIONS,
        ), true)) {
            return null;
        }
        foreach ([$call->name, '__call', 'getQuery'] as $method) {
            $metadata = $codebase->getMethod($class, $method) ?? $codebase->getDeclaringMethod($class, $method);
            if (
                $metadata !== null
                && ($method === $call->name
                    && strcasecmp($metadata->identifier->class ?? '', self::BUILDER) !== 0
                    || $method !== $call->name
                    && strcasecmp($metadata->identifier->class ?? '', self::RELATION) !== 0)
            ) {
                return null;
            }
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            foreach ([...($metadata->pseudoMethods ?? []), ...($metadata->staticPseudoMethods ?? [])] as $method) {
                if (strcasecmp($method, $call->name) === 0) {
                    return null;
                }
            }
        }
        $related = $receiver->parameters[0] ?? null;
        $model = $related?->atomicTypes[0] ?? null;
        if (
            $related === null
            || count($related->atomicTypes) !== 1
            || ! $model instanceof NamedObjectType
            || strcasecmp($model->name, 'Illuminate\\Database\\Eloquent\\Model') !== 0
            && ! in_array(
                'illuminate\\database\\eloquent\\model',
                array_map(strtolower(...), $codebase->getClassAncestors($model->name)),
                true,
            )
            || ! (new EloquentModelDispatch)->supportsModel($codebase, $model->name, $call->name)
        ) {
            return null;
        }

        return $related;
    }
}
