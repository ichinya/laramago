<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Resolve a bounded set of calls forwarded by standard Eloquent relations. */
final class EloquentRelationProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const PREFIX = 'Illuminate\\Database\\Eloquent\\Relations\\';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';
    private const RELATIONS = [
        'HasMany',
        'HasOne',
        'BelongsTo',
        'BelongsToMany',
        'HasManyThrough',
        'HasOneThrough',
        'MorphMany',
        'MorphOne',
        'MorphToMany',
        'MorphedByMany',
    ];
    private const SORTS = ['latest', 'oldest', 'orderby', 'orderbydesc'];
    private const AGGREGATES = ['count', 'sum', 'exists', 'doesntexist'];

    public function getTargets(): array
    {
        $targets = [];
        foreach (self::RELATIONS as $relation) {
            foreach (['first', 'firstorfail', 'sole', 'get', ...self::SORTS, ...self::AGGREGATES] as $method) {
                $targets[] = MethodTarget::exact(self::PREFIX.$relation, $method);
            }
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        return $this->relatedType($context->codebase, $context->invocation) === null
            ? null
            : (new EloquentModelDispatch)->signature(
                $context->codebase,
                $context->invocation->name,
                $this->methodClass($context->invocation->name),
            );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $model = $this->relatedType($context->codebase, $context->invocation);
        if ($model === null) {
            return null;
        }
        $name = strtolower($context->invocation->name);
        if (in_array($name, self::SORTS, true)) {
            // forwardDecoratedCallTo returns the relation when its builder returns itself.
            return $context->invocation->receiverType;
        }
        if ($name === 'get') {
            return (new EloquentCollectionType)->resolve($context->codebase, $model);
        }
        if (in_array($name, self::AGGREGATES, true)) {
            $method = $context->codebase->getMethod(self::QUERY, $name) ?? $context->codebase->getDeclaringMethod(
                self::QUERY,
                $name,
            );

            return $method?->returnType?->type ?? $method?->declaredReturnType?->type;
        }

        return $name === 'first' ? Type::union($model, Type::null()) : $model;
    }

    private function relatedType(Codebase $codebase, Invocation $call): ?Type
    {
        $atoms = $call->receiverType?->atomicTypes ?? [];
        $receiver = $atoms[0] ?? null;
        if (
            count($atoms) !== 1
            || ! $receiver instanceof NamedObjectType
            || ! in_array(
                $receiver->name,
                array_map(static fn (string $name): string => self::PREFIX.$name, self::RELATIONS),
                true,
            )
        ) {
            return null;
        }
        // Native relation methods and PHPDoc declarations retain priority.
        if (
            $codebase->getMethod($receiver->name, $call->name) !== null
            || $codebase->getDeclaringMethod($receiver->name, $call->name) !== null
        ) {
            return null;
        }
        foreach ($codebase->getMultipleClasses([
            $receiver->name,
            ...$codebase->getClassAncestors($receiver->name),
        ]) as $class) {
            foreach ([...($class?->pseudoMethods ?? []), ...($class?->staticPseudoMethods ?? [])] as $method) {
                if (strcasecmp($method, $call->name) === 0) {
                    return null;
                }
            }
        }
        $model = $receiver->parameters[0] ?? null;
        $modelAtom = $model?->atomicTypes[0] ?? null;
        if ($model === null || count($model->atomicTypes) !== 1 || ! $modelAtom instanceof NamedObjectType) {
            return null;
        }
        if (! (new EloquentModelDispatch)->supportsModel($codebase, $modelAtom->name, $call->name)) {
            return null;
        }
        if (in_array(strtolower($call->name), ['first', 'firstorfail', 'sole', 'get'], true)) {
            $dispatch = new EloquentModelDispatch;
            if (
                $dispatch->overrides($codebase, $modelAtom->name, 'newInstance')
                || $dispatch->overrides($codebase, $modelAtom->name, 'newFromBuilder')
            ) {
                return null;
            }
        }
        if (
            in_array(strtolower($call->name), [...self::AGGREGATES, 'orderby', 'orderbydesc'], true)
            && ($codebase->getMethod($modelAtom->name, 'scope'.ucfirst($call->name)) !== null
            || $codebase->getMethod($modelAtom->name, $call->name) !== null)
        ) {
            return null;
        }
        $methodClass = $this->methodClass($call->name);
        if (
            $codebase->getMethod($methodClass, $call->name) === null
            && $codebase->getDeclaringMethod($methodClass, $call->name) === null
        ) {
            return null;
        }

        return $model;
    }

    private function methodClass(string $method): string
    {
        return in_array(strtolower($method), [...self::AGGREGATES, 'orderby', 'orderbydesc'], true)
            ? self::QUERY
            : self::BUILDER;
    }
}
