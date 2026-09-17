<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\MacroIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
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

/** Supplies framework-known callback scopes without replacing application contracts. */
final class TestResponseCallbackProvider implements
    CallableSignatureOverride,
    MethodReturnTypeProvider,
    InitializationHook
{
    private const ASSERTABLE_INERTIA = 'Inertia\\Testing\\AssertableInertia';
    private const ASSERTABLE_JSON = 'Illuminate\\Testing\\Fluent\\AssertableJson';
    private const HAS_CONCERN = 'Illuminate\\Testing\\Fluent\\Concerns\\Has';
    private const INERTIA_MACROS = 'Inertia\\Testing\\TestResponseMacros';
    private const INERTIA_SERVICE_PROVIDER = 'Inertia\\ServiceProvider';
    private const TEST_RESPONSE = 'Illuminate\\Testing\\TestResponse';

    private ?MacroIndex $macros = null;
    private ?PhpSource $source = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->macros = null;
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::TEST_RESPONSE, 'assertInertia'),
            MethodTarget::exact(self::TEST_RESPONSE, 'inertiaPage'),
            MethodTarget::exact(self::TEST_RESPONSE, 'inertiaProps'),
            MethodTarget::exact(self::TEST_RESPONSE, 'assertInertiaFlash'),
            MethodTarget::exact(self::TEST_RESPONSE, 'assertInertiaFlashMissing'),
            MethodTarget::exact(self::TEST_RESPONSE, 'assertJson'),
            MethodTarget::exact(self::ASSERTABLE_JSON, 'has'),
            MethodTarget::exact(self::HAS_CONCERN, 'has'),
            MethodTarget::exact(self::ASSERTABLE_JSON, 'first'),
            MethodTarget::exact(self::ASSERTABLE_JSON, 'each'),
            MethodTarget::exact(self::ASSERTABLE_INERTIA, 'loadDeferredProps'),
            MethodTarget::exact(self::ASSERTABLE_INERTIA, 'reload'),
            MethodTarget::exact(self::ASSERTABLE_INERTIA, 'reloadOnly'),
            MethodTarget::exact(self::ASSERTABLE_INERTIA, 'reloadExcept'),
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
        if (in_array(
            $name,
            ['assertinertia', 'inertiapage', 'inertiaprops', 'assertinertiaflash', 'assertinertiaflashmissing'],
            true,
        )) {
            if (! $this->supportsTestResponseMacro($context->codebase, $receiver->name, $call->name)) {
                return null;
            }

            $parameters = match ($name) {
                'assertinertia' => [new CallableParameter(
                    '$callback',
                    Type::union($this->callback(Type::namedObject(self::ASSERTABLE_INERTIA)), Type::null()),
                    hasDefault: true,
                )],
                'inertiapage' => [],
                'inertiaprops' => [new CallableParameter(
                    '$propName',
                    Type::union(Type::string(), Type::null()),
                    hasDefault: true,
                )],
                'assertinertiaflash' => [
                    new CallableParameter('$key', Type::string()),
                    new CallableParameter('$expected', Type::mixed(), hasDefault: true),
                ],
                'assertinertiaflashmissing' => [new CallableParameter('$key', Type::string())],
            };

            return new EffectiveCallableSignature(
                $parameters,
                displayName: self::TEST_RESPONSE.'::'.$call->name,
            );
        }

        $method = $this->frameworkMethod($context->codebase, $receiver->name, $call->name);
        if ($method === null || $this->hasPseudoMethod($context->codebase, $receiver->name, $call->name)) {
            return null;
        }
        $signature = $this->signature($method);
        $scope = match ($name) {
            'assertjson', 'loaddeferredprops', 'reload', 'reloadonly', 'reloadexcept' => Type::namedObject(
                $name === 'assertjson' ? self::ASSERTABLE_JSON : self::ASSERTABLE_INERTIA,
            ),
            'has', 'first', 'each' => Type::namedObject($receiver->name, ...$receiver->parameters ?? []),
            default => null,
        };
        if ($scope === null) {
            return null;
        }
        $callback = $this->callback($scope, $name === 'assertjson' ? false : true);
        $parameters = [];
        foreach ($signature->parameters as $parameter) {
            $type = match ([$name, $parameter->name]) {
                ['assertjson', '$value'] => Type::union(
                    Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                    $callback,
                ),
                ['has', '$length'] => Type::union(Type::int(), Type::null(), $callback),
                ['first', '$callback'], ['each', '$callback'] => $callback,
                ['has', '$callback'],
                ['reload', '$callback'],
                ['reloadonly', '$callback'],
                ['reloadexcept', '$callback'],
                    => Type::union(Type::null(), $callback),
                ['loaddeferredprops', '$groupsOrCallback'] => Type::union(
                    Type::array(Type::int(), Type::string()),
                    Type::string(),
                    $callback,
                ),
                ['loaddeferredprops', '$callback'] => Type::union(Type::null(), $callback),
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
        $name = strtolower($context->invocation->name);
        if (! in_array(
            $name,
            ['assertinertia', 'inertiapage', 'inertiaprops', 'assertinertiaflash', 'assertinertiaflashmissing'],
            true,
        )) {
            return null;
        }
        $receiver = $this->receiver($context->invocation->receiverType);
        if (
            $receiver === null
            || ! $this->supportsTestResponseMacro(
                $context->codebase,
                $receiver->name,
                $context->invocation->name,
            )
        ) {
            return null;
        }

        if ($name === 'inertiapage') {
            return $this->supportsInertiaPage($context->codebase) ? InertiaResponseTypes::page() : null;
        }
        if ($name === 'inertiaprops') {
            $argument = $context->invocation->getArgument(0, 'propName');

            return $argument === null
            || $argument->type !== null && $context->types->isContainedBy($argument->type, Type::null())
                ? InertiaResponseTypes::props()
                : Type::mixed();
        }

        return Type::namedObject($receiver->name, ...$receiver->parameters ?? []);
    }

    /** Shared package and macro checks for response APIs that invoke the native macro. */
    public function supportsInertiaMacro(Codebase $codebase, string $method = 'assertInertia'): bool
    {
        if (
            ! $codebase->classExists(self::ASSERTABLE_INERTIA)
            || ! $codebase->classExists(self::INERTIA_MACROS)
            || ! $codebase->classExists(self::INERTIA_SERVICE_PROVIDER)
        ) {
            return false;
        }
        $factory = $this->method($codebase, self::INERTIA_MACROS, $method);
        $registration = $this->method($codebase, self::INERTIA_SERVICE_PROVIDER, 'registerTestingMacros');
        if (
            $factory === null
            || $factory->static
            || $factory->parameters !== []
            || strcasecmp($factory->identifier->class ?? '', self::INERTIA_MACROS) !== 0
            || $registration === null
            || $registration->static
            || $registration->parameters !== []
            || strcasecmp($registration->identifier->class ?? '', self::INERTIA_SERVICE_PROVIDER) !== 0
        ) {
            return false;
        }

        return ! $this->hasUserMacroOverride($codebase, $method);
    }

    public function supportsInertiaPage(
        Codebase $codebase,
        ?FunctionLikeMetadata $forwarder = null,
    ): bool {
        if (! $this->supportsInertiaMacro($codebase, 'inertiaPage')) {
            return false;
        }
        $source = $this->source ??= new PhpSource($this->root);

        return (
            InertiaResponseTypes::supportsPage($codebase, $source)
            && ($forwarder === null || InertiaResponseTypes::forwardsPage($forwarder, $source))
        );
    }

    private function supportsTestResponseMacro(Codebase $codebase, string $receiver, string $method): bool
    {
        if (
            ! $this->inherits($codebase, $receiver, self::TEST_RESPONSE)
            || ! $this->supportsInertiaMacro($codebase, $method)
        ) {
            return false;
        }
        $declared = $this->method($codebase, $receiver, $method);
        if ($declared !== null || $this->hasPseudoMethod($codebase, $receiver, $method)) {
            return false;
        }
        $dispatch = $this->method($codebase, $receiver, '__call');
        if ($dispatch === null || strcasecmp($dispatch->identifier->class ?? '', self::TEST_RESPONSE) !== 0) {
            return false;
        }

        return true;
    }

    private function frameworkMethod(Codebase $codebase, string $receiver, string $name): ?FunctionLikeMetadata
    {
        $method = $this->method($codebase, $receiver, $name);
        $expected = match (strtolower($name)) {
            'assertjson' => [self::TEST_RESPONSE],
            'has' => [self::HAS_CONCERN, self::ASSERTABLE_JSON],
            'first', 'each' => [self::ASSERTABLE_JSON],
            'loaddeferredprops', 'reload', 'reloadonly', 'reloadexcept' => [self::ASSERTABLE_INERTIA],
            default => [],
        };
        if ($method === null || $method->static || ! $this->hasExpectedParameters($method, $name)) {
            return null;
        }
        foreach ($expected as $class) {
            if (strcasecmp($method->identifier->class ?? '', $class) === 0) {
                return $method;
            }
        }

        return null;
    }

    private function hasExpectedParameters(FunctionLikeMetadata $method, string $name): bool
    {
        $expected = match (strtolower($name)) {
            'assertjson' => [['$value', false], ['$strict', true]],
            'has' => [['$key', false], ['$length', true], ['$callback', true]],
            'first', 'each' => [['$callback', false]],
            'loaddeferredprops' => [
                ['$groupsOrCallback', false],
                ['$callback',         true],
            ],
            'reload' => [['$callback', true], ['$only', true], ['$except', true]],
            'reloadonly', 'reloadexcept' => [['$only', false], ['$callback', true]],
            default => [],
        };
        if (strtolower($name) === 'reloadexcept') {
            $expected[0][0] = '$except';
        }
        if (count($method->parameters) !== count($expected)) {
            return false;
        }
        foreach ($expected as $position => [$parameterName, $hasDefault]) {
            $parameter = $method->parameters[$position];
            if (
                $parameter->name !== $parameterName
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) !== $hasDefault
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasUserMacroOverride(Codebase $codebase, string $method): bool
    {
        $index = $this->macros ??= new MacroIndex($this->root);
        if ($index->hasUnknownRegistrations()) {
            return true;
        }
        foreach ($index->registrationClasses() as $class) {
            if (! $this->inherits($codebase, $class, self::TEST_RESPONSE)) {
                continue;
            }
            if ($index->mayRegister($class, $method)) {
                return true;
            }
        }

        return false;
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

    private function callback(Type $scope, bool $closure = true): Type
    {
        return Type::fromAtomic(
            new CallableType(
                new CallableSignature(
                    false,
                    $closure,
                    [new CallableParameter('$scope', $scope)],
                    Type::mixed(),
                    null,
                    [],
                ),
                null,
            ),
        );
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

    private function inherits(Codebase $codebase, string $class, string $parent): bool
    {
        return (
            strcasecmp($class, $parent) === 0
            || in_array(strtolower($parent), array_map(strtolower(...), $codebase->getClassAncestors($class)), true)
        );
    }
}
