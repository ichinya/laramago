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

/** Bind common model reads and sorting to their installed builder signatures. */
final class EloquentQueryProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';
    private const READS = ['first', 'firstorfail', 'sole', 'get'];
    private const SORTS = ['latest', 'oldest', 'orderby', 'orderbydesc'];
    private const FORWARDED = ['orderby', 'orderbydesc', 'count', 'sum', 'exists', 'doesntexist'];

    private readonly EloquentModelDispatch $dispatch;
    private readonly EloquentCollectionType $collections;

    public function __construct()
    {
        $this->dispatch = new EloquentModelDispatch;
        $this->collections = new EloquentCollectionType;
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ([...self::READS, ...self::SORTS, 'count', 'sum', 'exists', 'doesntexist'] as $method) {
            $targets[] = MethodTarget::exact(self::MODEL, $method);
        }
        foreach (['orderby', 'orderbydesc', 'get'] as $method) {
            $targets[] = MethodTarget::exact(self::BUILDER, $method);
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        return $this->modelType($context->codebase, $context->invocation) === null
            ? null
            : $this->dispatch->signature(
                $context->codebase,
                $context->invocation->name,
                $this->methodClass($context->invocation->name),
            );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $model = $this->modelType($context->codebase, $call);
        if ($model === null) {
            return null;
        }
        $name = strtolower($call->name);
        if (in_array($name, self::SORTS, true)) {
            return Type::namedObject(self::BUILDER, $model);
        }
        if ($name === 'get') {
            return $this->collections->resolve($context->codebase, $model);
        }
        if (in_array($name, self::READS, true)) {
            return $name === 'first' ? Type::union($model, Type::null()) : $model;
        }
        $method = $context->codebase->getMethod(self::QUERY, $name) ?? $context->codebase->getDeclaringMethod(
            self::QUERY,
            $name,
        );

        // Preserve the installed aggregate contract; SUM need not be an integer.
        return $method->returnType->type ?? $method?->declaredReturnType?->type;
    }

    private function modelType(Codebase $codebase, Invocation $call): ?Type
    {
        $name = strtolower($call->name);
        $model = $this->receiverModelType($codebase, $call);
        if ($model === null || count($model->atomicTypes) !== 1) {
            return null;
        }
        $atom = $model->atomicTypes[0];
        if (! $atom instanceof NamedObjectType) {
            return null;
        }
        if (in_array($name, self::FORWARDED, true)) {
            // Named scopes precede Query Builder forwarding in Eloquent::__call.
            if (
                $codebase->getMethod($atom->name, $name) !== null
                || $codebase->getDeclaringMethod($atom->name, $name) !== null
                || $codebase->getMethod($atom->name, 'scope'.ucfirst($name)) !== null
                || $codebase->getDeclaringMethod($atom->name, 'scope'.ucfirst($name)) !== null
                || $this->dispatch->overrides($codebase, $atom->name, 'hasNamedScope')
                || $this->dispatch->overrides($codebase, $atom->name, 'callNamedScope')
            ) {
                return null;
            }
        }
        if (in_array($name, self::READS, true)) {
            if (
                $this->dispatch->overrides($codebase, $atom->name, 'newInstance')
                || $this->dispatch->overrides($codebase, $atom->name, 'newFromBuilder')
                || $this->collections->resolve($codebase, $model) === null
            ) {
                return null;
            }
        }

        return $model;
    }

    private function receiverModelType(Codebase $codebase, Invocation $call): ?Type
    {
        $receiverType = $call->receiverType;
        if ($receiverType === null || count($receiverType->atomicTypes) !== 1) {
            return null;
        }
        $receiver = $receiverType->atomicTypes[0];
        if (! $receiver instanceof NamedObjectType || strcasecmp($receiver->name, self::BUILDER) !== 0) {
            return $this->dispatch->modelType($codebase, $call, $this->methodClass($call->name));
        }
        if (strtolower($call->name) === 'get' && $codebase->getMethod(self::BUILDER, 'get') !== null) {
            return $receiver->parameters[0] ?? null;
        }
        if (
            $codebase->getMethod(self::BUILDER, $call->name) !== null
            || $codebase->getDeclaringMethod(self::BUILDER, $call->name) !== null
            || $codebase->getMethod(self::QUERY, $call->name) === null
        ) {
            return null;
        }

        return $receiver->parameters[0] ?? null;
    }

    private function methodClass(string $name): string
    {
        return in_array(strtolower($name), self::FORWARDED, true) ? self::QUERY : self::BUILDER;
    }
}
