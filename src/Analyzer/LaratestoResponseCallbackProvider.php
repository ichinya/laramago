<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Contextual callback scopes for the optional Laratesto response API. */
final class LaratestoResponseCallbackProvider implements CallableSignatureOverride, MethodReturnTypeProvider
{
    private const ASSERTABLE_INERTIA = 'Inertia\\Testing\\AssertableInertia';
    private const ASSERTABLE_JSON = 'Laratesto\\Testing\\AssertableJson';
    private const RESPONSE = 'Laratesto\\Testing\\LaravelResponse';

    public function __construct(
        private readonly TestResponseCallbackProvider $httpTests,
    ) {}

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::RESPONSE, 'assertInertia'),
            MethodTarget::exact(self::RESPONSE, 'inertiaPage'),
            MethodTarget::exact(self::RESPONSE, 'assertJson'),
            MethodTarget::exact(self::ASSERTABLE_JSON, 'has'),
            MethodTarget::exact(self::ASSERTABLE_JSON, 'first'),
            MethodTarget::exact(self::ASSERTABLE_JSON, 'each'),
        ];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $receiver = $this->receiver($call->receiverType);
        if ($receiver === null) {
            return null;
        }
        $name = strtolower($call->name);
        $method = $this->frameworkMethod($context->codebase, $receiver->name, $call->name);
        if (
            $method === null
            || $this->hasPseudoMethod($context->codebase, $receiver->name, $call->name)
            || $name === 'assertinertia'
            && ! $this->httpTests->supportsInertiaMacro($context->codebase)
        ) {
            return null;
        }
        $signature = $this->signature($method);
        $scope = match ($name) {
            'assertinertia' => Type::namedObject(self::ASSERTABLE_INERTIA),
            'assertjson' => Type::namedObject(self::ASSERTABLE_JSON),
            'has', 'first', 'each' => Type::namedObject($receiver->name, ...$receiver->parameters ?? []),
            default => null,
        };
        if ($scope === null) {
            return null;
        }
        $callback = $this->callback($scope, $name !== 'assertjson');
        $parameters = [];
        foreach ($signature->parameters as $parameter) {
            $type = match ([$name, $parameter->name]) {
                ['assertinertia', '$callback'] => Type::union(Type::null(), $callback),
                ['assertjson', '$expected'] => Type::union(
                    Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                    $callback,
                ),
                ['has', '$length'] => Type::union(Type::int(), Type::null(), $callback),
                ['has', '$callback'] => Type::union(Type::null(), $callback),
                ['first', '$callback'], ['each', '$callback'] => $callback,
                default => $parameter->type,
            };
            if (
                $type !== null
                && $parameter->type !== null
                && ! $context->types->isContainedBy($type, $parameter->type)
            ) {
                return null;
            }
            $parameters[] = new CallableParameter(
                $parameter->name,
                $type,
                $parameter->closureThisType,
                $parameter->byReference,
                $parameter->variadic,
                $parameter->hasDefault,
            );
        }

        return new EffectiveCallableSignature(
            $parameters,
            $signature->allowsNamedArguments,
            ($method->identifier->class ?? $receiver->name).'::'.$method->originalName,
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        if (strcasecmp($context->invocation->name, 'inertiaPage') !== 0 || $context->invocation->arguments !== []) {
            return null;
        }
        $receiver = $this->receiver($context->invocation->receiverType);
        if ($receiver === null || $this->hasPseudoMethod($context->codebase, $receiver->name, 'inertiaPage')) {
            return null;
        }
        $method = $this->frameworkMethod($context->codebase, $receiver->name, 'inertiaPage');
        $return = $method?->returnType?->type ?? $method?->declaredReturnType?->type;
        if (
            $method === null
            || (string) $return !== 'mixed'
            || ! $this->httpTests->supportsInertiaPage($context->codebase, $method)
        ) {
            return null;
        }

        return InertiaResponseTypes::page();
    }

    private function frameworkMethod(Codebase $codebase, string $receiver, string $name): ?FunctionLikeMetadata
    {
        $method = $this->method($codebase, $receiver, $name);
        $expected = match (strtolower($name)) {
            'assertinertia', 'assertjson', 'inertiapage' => self::RESPONSE,
            'has', 'first', 'each' => self::ASSERTABLE_JSON,
            default => null,
        };
        if (
            $method === null
            || $method->static
            || $expected === null
            || strcasecmp($method->identifier->class ?? '', $expected) !== 0
            || ! $this->hasPackageOrigin($method, $expected)
            || ! $this->hasExpectedParameters($method, $name)
        ) {
            return null;
        }

        return $method;
    }

    private function hasExpectedParameters(FunctionLikeMetadata $method, string $name): bool
    {
        $expected = match (strtolower($name)) {
            'assertinertia' => [['$callback', true]],
            'inertiapage' => [['$key', true]],
            'assertjson' => [['$expected', false], ['$strict', true]],
            'has' => [['$key', false], ['$length', true], ['$callback', true]],
            'first', 'each' => [['$callback', false]],
            default => [],
        };
        if (count($method->parameters) !== count($expected)) {
            return false;
        }
        foreach ($expected as $position => [$name, $hasDefault]) {
            $parameter = $method->parameters[$position];
            if (
                $parameter->name !== $name
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) !== $hasDefault
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasPackageOrigin(FunctionLikeMetadata $method, string $class): bool
    {
        $file = strtolower(str_replace('\\', '/', $method->location->file ?? ''));
        $name = $class === self::RESPONSE ? 'laravelresponse.php' : 'assertablejson.php';

        return str_ends_with($file, '/ichinya/laratesto/src/testing/'.$name);
    }

    private function hasPseudoMethod(Codebase $codebase, string $receiver, string $method): bool
    {
        foreach ($codebase->getMultipleClasses([$receiver, ...$codebase->getClassAncestors($receiver)]) as $class) {
            foreach ([...($class?->pseudoMethods ?? []), ...($class?->staticPseudoMethods ?? [])] as $documented) {
                if (strcasecmp($documented, $method) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function signature(FunctionLikeMetadata $method): EffectiveCallableSignature
    {
        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $parameters[] = new CallableParameter(
                $parameter->name,
                $parameter->type?->type ?? $parameter->declaredType?->type,
                $parameter->closureThisType?->type,
                $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                $parameter->flags->contains(MetadataFlags::VARIADIC),
                $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }

        return new EffectiveCallableSignature($parameters);
    }

    private function callback(Type $scope, bool $closure): Type
    {
        return Type::fromAtomic(new CallableType(
            new CallableSignature(
                false,
                $closure,
                [new CallableParameter('$scope', $scope)],
                Type::mixed(),
                null,
                [],
            ),
            null,
        ));
    }

    private function receiver(?Type $type): ?NamedObjectType
    {
        $atom = $type !== null && count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;

        return $atom instanceof NamedObjectType ? $atom : null;
    }

    private function method(Codebase $codebase, string $class, string $method): ?FunctionLikeMetadata
    {
        return $codebase->getMethod($class, $method) ?? $codebase->getDeclaringMethod($class, $method);
    }
}
