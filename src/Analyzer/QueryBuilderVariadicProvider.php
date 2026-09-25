<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeQueryBuilderVariadics;
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

/** Query Builder select() and distinct() accept positional func_get_args(). */
final class QueryBuilderVariadicProvider implements MethodReturnTypeProvider, CallableSignatureOverride
{
    private const BUILDER = 'Illuminate\Database\Query\Builder';
    private const ELOQUENT_BUILDER = 'Illuminate\Database\Eloquent\Builder';
    private const BELONGS_TO_MANY = 'Illuminate\Database\Eloquent\Relations\BelongsToMany';
    private const HAS_MANY = 'Illuminate\Database\Eloquent\Relations\HasMany';

    private readonly NativeQueryBuilderVariadics $contract;

    public function __construct(string $root)
    {
        $this->contract = new NativeQueryBuilderVariadics($root);
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::BUILDER, 'select'),
            MethodTarget::exact(self::BUILDER, 'distinct'),
        ];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        $name = strtolower($call->name);
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || $call->receiverType === null
            || count($call->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || ! in_array(strtolower($receiver->name), [
                strtolower(self::BUILDER),
                strtolower(self::ELOQUENT_BUILDER),
                strtolower(self::BELONGS_TO_MANY),
                strtolower(self::HAS_MANY),
            ], strict: true)
            || strcasecmp($call->declaringClass ?? '', self::BUILDER) !== 0
            || count($call->arguments) <= ($name === 'select' ? 1 : 0)
        ) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->name !== null || $argument->unpacked) {
                return null;
            }
        }
        if (! $this->contract->proves($context->codebase, $name)) {
            return null;
        }

        return new EffectiveCallableSignature(
            $name === 'select'
                ? [new CallableParameter('$columns', Type::mixed()), new CallableParameter('$additionalColumns', Type::mixed(), variadic: true)]
                : [new CallableParameter('$columns', Type::mixed(), variadic: true)],
            allowsNamedArguments: false,
            displayName: self::BUILDER.'::'.$name,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return null;
    }
}
