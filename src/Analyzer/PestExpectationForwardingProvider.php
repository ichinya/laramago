<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PestExpectationContract;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ReferenceType;
use Mago\Sdk\Analyzer\Type\ReferenceTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Corrects the result of Pest's declared builtin expectation forwarding. */
final class PestExpectationForwardingProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const EXPECTATION = 'Pest\\Expectation';
    private const MIXIN = 'Pest\\Mixins\\Expectation';
    private const OPPOSITE = 'Pest\\Expectations\\OppositeExpectation';
    private const EACH = 'Pest\\Expectations\\EachExpectation';
    private const HIGHER_ORDER = 'Pest\\Expectations\\HigherOrderExpectation';

    private PestExpectationContract $contract;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->contract = new PestExpectationContract($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->contract = new PestExpectationContract($this->root);
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::allMethods(self::EXPECTATION),
            MethodTarget::allMethods(self::MIXIN),
            MethodTarget::allMethods(self::OPPOSITE),
            MethodTarget::allMethods(self::EACH),
            MethodTarget::allMethods(self::HIGHER_ORDER),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if ($call->kind !== InvocationKind::InstanceMethod) {
            return null;
        }
        $receiver = $call->receiverType;
        $atom = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $atom instanceof NamedObjectType
            || ($atom->intersections ?? []) !== []
            || $atom->static
            || $atom->isThis
        ) {
            return null;
        }
        $wrapper = $atom->name;
        if (! in_array($wrapper, [self::EXPECTATION, self::OPPOSITE, self::EACH, self::HIGHER_ORDER], true)) {
            return null;
        }
        $codebase = $context->codebase;
        $method = $codebase->getMethod(self::MIXIN, $call->name) ?? $codebase->getDeclaringMethod(
            self::MIXIN,
            $call->name,
        );
        $mixinReturn = $method?->declaredReturnType?->type;
        $mixinEffective = $method?->returnType?->type ?? $mixinReturn;
        $mixinAtom = $mixinReturn?->atomicTypes[0] ?? null;
        $effectiveAtom = $mixinEffective?->atomicTypes[0] ?? null;
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::MIXIN) !== 0
            || $method->visibility !== Visibility::Public
            || $method->static
            || $mixinReturn === null
            || count($mixinReturn->atomicTypes) !== 1
            || ! $mixinAtom instanceof NamedObjectType
            || strcasecmp($mixinAtom->name, self::MIXIN) !== 0
            || $mixinEffective === null
            || count($mixinEffective->atomicTypes) !== 1
            || ! $effectiveAtom instanceof NamedObjectType
            || strcasecmp($effectiveAtom->name, self::MIXIN) !== 0
            || self::declares($codebase, $wrapper, $call->name)
            || ! $this->forwardingContract($codebase, $wrapper)
        ) {
            return null;
        }
        $parameters = $atom->parameters ?? [];
        if (
            $wrapper === self::HIGHER_ORDER
            && count($parameters) !== 2
            || $wrapper !== self::HIGHER_ORDER
            && count($parameters) > 1
        ) {
            return null;
        }

        // Pest's mixin methods are called through Expectation::__call, which
        // returns the original expectation. Negation returns that original as
        // well; each/higher-order forwarding retains its wrapper.
        $result = $wrapper === self::OPPOSITE ? self::EXPECTATION : $wrapper;

        return Type::namedObject($result, ...$parameters);
    }

    private static function declares(Codebase $codebase, string $class, string $name): bool
    {
        $metadata = $codebase->getClass($class);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return true;
        }
        foreach ([...$metadata->methods, ...$metadata->pseudoMethods] as $method) {
            if (strcasecmp($method, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function forwardingContract(Codebase $codebase, string $wrapper): bool
    {
        if ($codebase->getFunction('Pest\\method_exists') !== null) {
            return false;
        }
        $expectation = $codebase->getClass(self::EXPECTATION);
        if ($expectation === null || ! $this->contract->matches(self::EXPECTATION, $expectation->location->file)) {
            return false;
        }
        $dispatch = $codebase->getMethod(self::EXPECTATION, '__call');
        if (
            $dispatch === null
            || ! self::effectiveReturnIs(
                $dispatch->returnType?->type,
                [
                    self::EXPECTATION,
                    self::HIGHER_ORDER,
                    // Mago keeps this unresolved name from Pest's short PHPDoc type.
                    'Pest\\HigherOrderExpectation',
                    'Pest\\Arch\\PendingArchExpectation',
                    'Pest\\Arch\\Contracts\\ArchExpectation',
                ],
                self::EXPECTATION,
            )
        ) {
            return false;
        }
        $hasMixin = false;
        foreach ($expectation->mixins as $mixin) {
            $atom = $mixin->atomicTypes[0];
            $hasMixin = $hasMixin || $atom instanceof NamedObjectType && strcasecmp($atom->name, self::MIXIN) === 0;
        }
        if (! $hasMixin || $wrapper === self::EXPECTATION) {
            return $hasMixin;
        }
        $metadata = $codebase->getClass($wrapper);
        $method = $codebase->getMethod($wrapper, '__call') ?? $codebase->getDeclaringMethod($wrapper, '__call');
        if (
            $metadata === null
            || $method === null
            || $method->visibility !== Visibility::Public
            || $method->static
            || ! $this->contract->matches($wrapper, $metadata->location->file)
        ) {
            return false;
        }
        $declared = $method->declaredReturnType?->type;
        $atom = $declared?->atomicTypes[0] ?? null;
        $expected = $wrapper === self::OPPOSITE ? self::EXPECTATION : $wrapper;

        $documented = $wrapper === self::OPPOSITE
            ? [self::EXPECTATION, 'Pest\\Expectations\\Expectation']
            : [$expected];
        if (! self::effectiveReturnIs($method->returnType?->type, $documented, $expected)) {
            return false;
        }

        return (
            $declared !== null
            && count($declared->atomicTypes) === 1
            && $atom instanceof NamedObjectType
            && strcasecmp($atom->name, $expected) === 0
        );
    }

    /** @param list<string> $allowed */
    private static function effectiveReturnIs(?Type $type, array $allowed, string $required): bool
    {
        if ($type === null) {
            return false;
        }
        $found = false;
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof NamedObjectType) {
                if (! in_array($atom->name, $allowed, true)) {
                    return false;
                }
                $found =
                    $found
                    || $atom->name === $required
                    || $required === self::EXPECTATION && $atom->name === 'Pest\\Expectations\\Expectation';
            } elseif ($atom instanceof ReferenceType) {
                if (
                    $atom->kind !== ReferenceTypeKind::Symbol
                    || $atom->name === null
                    || ! in_array($atom->name, $allowed, true)
                ) {
                    return false;
                }
                $found =
                    $found
                    || $atom->name === $required
                    || $required === self::EXPECTATION && $atom->name === 'Pest\\Expectations\\Expectation';
            } elseif (! $atom instanceof SimpleAtomicType || $atom->kind !== SimpleAtomicTypeKind::Never) {
                return false;
            }
        }

        return $found;
    }
}
