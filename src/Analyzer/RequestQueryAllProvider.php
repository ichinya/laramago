<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeRequestQueryAll;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Refines only the source-proven no-argument Laravel request query call. */
final class RequestQueryAllProvider implements MethodReturnTypeProvider
{
    private const REQUEST = 'Illuminate\\Http\\Request';
    private const INPUT_TRAIT = 'Illuminate\\Http\\Concerns\\InteractsWithInput';

    private readonly NativeRequestQueryAll $contract;

    public function __construct(string $root)
    {
        $this->contract = new NativeRequestQueryAll($root);
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::REQUEST, 'query'),
            MethodTarget::exact(self::INPUT_TRAIT, 'query'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || $call->arguments !== []
            || $call->receiverType === null
            || count($call->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || $receiver->name !== self::REQUEST
        ) {
            return null;
        }

        $array = Type::array(Type::union(Type::int(), Type::string()), Type::mixed());

        return $this->contract->proves($context, $array) ? $array : null;
    }
}
