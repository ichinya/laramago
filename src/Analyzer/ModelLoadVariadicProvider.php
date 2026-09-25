<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeModelLoad;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Eloquent Model::load() accepts multiple positional relationship names. */
final class ModelLoadVariadicProvider implements MethodReturnTypeProvider, CallableSignatureOverride
{
    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    private readonly NativeModelLoad $contract;

    public function __construct(string $root)
    {
        $this->contract = new NativeModelLoad($root);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::MODEL, 'load')];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || $call->receiverType === null
            || count($call->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || strcasecmp($call->declaringClass ?? '', self::MODEL) !== 0
            || count($call->arguments) < 2
        ) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->name !== null || $argument->unpacked) {
                return null;
            }
        }
        if (! $this->contract->proves($context->codebase)) {
            return null;
        }

        return new EffectiveCallableSignature([
            new CallableParameter('$relations', Type::mixed()),
            new CallableParameter('$additionalRelations', Type::mixed(), variadic: true),
        ], allowsNamedArguments: false, displayName: self::MODEL.'::load');
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return null;
    }
}
