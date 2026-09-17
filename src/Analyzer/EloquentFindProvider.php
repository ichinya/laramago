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

/** Selects the scalar or collection branch of Eloquent primary-key lookups. */
final class EloquentFindProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const ARRAYABLE = 'Illuminate\\Contracts\\Support\\Arrayable';

    private readonly EloquentModelDispatch $dispatch;

    public function __construct()
    {
        $this->dispatch = new EloquentModelDispatch;
    }

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

        return $this->dispatch->signature($context->codebase, $context->invocation->name);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $model = $this->modelType($context->codebase, $call);
        if ($model === null) {
            return null;
        }
        $name = strtolower($call->name);
        $collection = (new EloquentCollectionType)->resolve($context->codebase, $model);
        if ($collection === null) {
            return null;
        }
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

            return $model !== null && $this->knownCollections($codebase, $model) ? $model : null;
        }
        $model = $this->dispatch->modelType($codebase, $call);

        return $model !== null && $this->knownCollections($codebase, $model) ? $model : null;
    }

    /** Only statically resolved collection contracts can specialize lookup results. */
    private function knownCollections(Codebase $codebase, Type $model): bool
    {
        return (new EloquentCollectionType)->resolve($codebase, $model) !== null;
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
