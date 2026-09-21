<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\MacroIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ScopeBodyInference;
use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Reads local scope contracts without invoking models or scope bodies. */
final class EloquentScopeProvider implements MethodReturnTypeProvider, CallableSignatureProvider, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    private ?PhpSource $source = null;
    private ?MacroIndex $macros = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->macros = null;
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::allMethods(self::MODEL),
            MethodTarget::allMethods(self::BUILDER),
            ...array_map(
                static fn (string $method): MethodTarget => MethodTarget::exact(
                    'Illuminate\\Database\\Query\\Builder',
                    $method,
                ),
                EloquentQueryProvider::predicateMethods(),
            ),
        ];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = (new EloquentScopeResolver)->resolve($context->codebase, $context->invocation, $context->types);
        if ($resolved === null) {
            return null;
        }
        [$method] = $resolved;
        $parameters = [];
        foreach (array_slice($method->parameters, 1) as $parameter) {
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $parameter->type->type ?? $parameter->declaredType?->type,
                closureThisType: $parameter->closureThisType?->type,
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }

        return new EffectiveCallableSignature(
            $parameters,
            displayName: ($method->identifier->class ?? self::MODEL).'::'.$method->originalName,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = (new EloquentScopeResolver)->resolve($context->codebase, $context->invocation, $context->types);
        if ($resolved === null) {
            return null;
        }
        [$method, $model] = $resolved;
        $return = $method->returnType->type ?? $method->declaredReturnType?->type;
        if ($return === null) {
            $modelAtom = $model->atomicTypes[0];
            if (! $modelAtom instanceof NamedObjectType) {
                return null;
            }

            return (new ScopeBodyInference(
                $context->codebase,
                $this->source ??= new PhpSource($this->root),
                $modelAtom->name,
                $this->macros ??= new MacroIndex($this->root),
            ))->preservesQuery($method)
                ? Type::namedObject(self::BUILDER, $model)
                : null;
        }
        $builder = Type::namedObject(self::BUILDER, $model);
        $result = null;
        foreach ($return->atomicTypes as $atom) {
            $type = Type::fromAtomic($atom);
            // callScope uses the current builder only when the scope returns null.
            $type = in_array((string) $type, ['void', 'null'], true) ? $builder : $type;
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }
}
