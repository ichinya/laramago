<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeRequestOnly;
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

/** The installed Request::only implementation accepts positional func_get_args(). */
final class RequestOnlyVariadicProvider implements MethodReturnTypeProvider, CallableSignatureOverride
{
    private const REQUEST = 'Illuminate\Http\Request';
    private const TRAIT = 'Illuminate\Support\Traits\InteractsWithData';

    private readonly NativeRequestOnly $contract;

    public function __construct(string $root)
    {
        $this->contract = new NativeRequestOnly($root);
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::REQUEST, 'only'),
            MethodTarget::exact(self::TRAIT, 'only'),
        ];
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
            || strcasecmp($receiver->name, self::REQUEST) !== 0
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
            new CallableParameter('$keys', Type::mixed()),
            new CallableParameter('$additionalKeys', Type::mixed(), variadic: true),
        ], allowsNamedArguments: false, displayName: self::TRAIT.'::only');
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return null;
    }
}
