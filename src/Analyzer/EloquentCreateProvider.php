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

/** Resolves model creation without executing constructors, events or queries. */
final class EloquentCreateProvider implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private readonly EloquentModelDispatch $dispatch;

    public function __construct()
    {
        $this->dispatch = new EloquentModelDispatch;
    }

    public function getTargets(): array
    {
        return array_map(
            static fn (string $method): MethodTarget => MethodTarget::exact(
                'Illuminate\\Database\\Eloquent\\Model',
                $method,
            ),
            [
                'create',
                'createQuietly',
                'forceCreate',
                'forceCreateQuietly',
                'firstOrNew',
                'firstOrCreate',
                'createOrFirst',
                'updateOrCreate',
            ],
        );
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        return $this->modelType($context->codebase, $context->invocation) === null
            ? null
            : $this->dispatch->signature($context->codebase, $context->invocation->name);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return $this->modelType($context->codebase, $context->invocation);
    }

    private function modelType(Codebase $codebase, Invocation $call): ?Type
    {
        $model = $this->dispatch->modelType($codebase, $call);
        $atom = $model?->atomicTypes[0] ?? null;
        if (! $atom instanceof NamedObjectType) {
            return null;
        }
        // Instance factories and force-create dispatch can replace the returned object.
        foreach ([
            'newInstance' => null,
            'newFromBuilder' => null,
            'create' => null,
            'unguarded' => 'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes',
            'withoutEvents' => 'Illuminate\\Database\\Eloquent\\Concerns\\HasEvents',
        ] as $name => $trait) {
            if ($this->dispatch->overrides($codebase, $atom->name, $name, $trait)) {
                return null;
            }
        }

        return $model;
    }
}
