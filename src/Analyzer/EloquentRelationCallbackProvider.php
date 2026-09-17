<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Contextual callback typing for literal, statically resolved relationship paths. */
final class EloquentRelationCallbackProvider implements CallableSignatureOverride, MethodReturnTypeProvider
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const METHODS = ['wherehas', 'orwherehas', 'wheredoesnthave', 'orwheredoesnthave', 'withwherehas'];

    public function __construct(
        private readonly string $root,
    ) {}

    public function getTargets(): array
    {
        return [
            ...array_map(static fn (string $method): MethodTarget => MethodTarget::exact(
                self::BUILDER,
                $method,
            ), self::METHODS),
            ...array_map(static fn (string $method): MethodTarget => MethodTarget::exact(
                self::MODEL,
                $method,
            ), self::METHODS),
        ];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $atom = $receiver !== null && count($receiver->atomicTypes) === 1 ? $receiver->atomicTypes[0] : null;
        if (! $atom instanceof NamedObjectType) {
            return null;
        }
        $dispatch = new EloquentModelDispatch;
        $onBuilder = strcasecmp($atom->name, self::BUILDER) === 0;
        $model = $onBuilder ? $atom->parameters[0] ?? null : $dispatch->modelType($context->codebase, $call);
        $modelAtom = $model !== null && count($model->atomicTypes) === 1 ? $model->atomicTypes[0] : null;
        if (
            $model === null
            || ! $modelAtom instanceof NamedObjectType
            || ! $dispatch->supportsModel($context->codebase, $modelAtom->name, $call->name)
        ) {
            return null;
        }
        $native = $dispatch->signature($context->codebase, $call->name);
        $match = [];
        $argument = $call->getArgument(0, 'relation');
        // Before argument analysis only source expressions are available. Accept
        // plain quoted identifiers, without evaluating expressions or escapes.
        if (
            $argument === null
            || $argument->unpacked
            || ! preg_match(
                '/^[\'\"]([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)[\'\"]$/D',
                $argument->expression,
                $match,
            )
        ) {
            return $native;
        }
        $relation = (new EloquentRelationCallbackType(new PhpSource($this->root)))->relation(
            $context->codebase,
            $model,
            $match[1],
            $call->name,
        );
        $relationAtom = $relation?->atomicTypes[0] ?? null;
        $related = $relationAtom instanceof NamedObjectType ? $relationAtom->parameters[0] ?? null : null;
        if ($relation === null || $related === null) {
            return $native;
        }
        if ($native === null) {
            return null;
        }
        $query = Type::namedObject(self::BUILDER, $related);
        // Existence constraints receive a Builder; eager-load constraints receive
        // the actual Relation. The callback must accept both invocations.
        if (strcasecmp($call->name, 'withWhereHas') === 0) {
            $query = Type::union($query, $relation);
        }
        $callback = Type::fromAtomic(
            new CallableType(
                new CallableSignature(false, true, [new CallableParameter('$query', $query)], Type::mixed(), null, []),
                null,
            ),
        );
        $parameters = [];
        foreach ($native->parameters as $parameter) {
            $parameters[] = new CallableParameter(
                $parameter->name,
                $parameter->name === '$callback' ? Type::union($callback, Type::null()) : $parameter->type,
                $parameter->closureThisType,
                $parameter->byReference,
                $parameter->variadic,
                $parameter->hasDefault,
            );
        }

        return new EffectiveCallableSignature($parameters, displayName: self::BUILDER.'::'.$call->name);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        // The signature provider may be used for a magic model call. Return
        // refinement is independent of whether its relation name was literal.
        $model = (new EloquentModelDispatch)->modelType($context->codebase, $context->invocation);

        return $model === null ? null : Type::namedObject(self::BUILDER, $model);
    }
}
