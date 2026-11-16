<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\MacroCallable;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MacroIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MacroInvocationNames;
use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Preserves native dispatch and documentation before considering static macros. */
final class MacroProvider implements MethodReturnTypeProvider, CallableSignatureProvider, InitializationHook
{
    private const TRAIT = 'Illuminate\\Support\\Traits\\Macroable';

    private ?MacroIndex $index = null;
    public readonly MacroInvocationNames $calls;

    public function __construct(
        private readonly string $root,
    ) { $this->calls = new MacroInvocationNames($root); }

    public function initialize(InitializationContext $context): void
    {
        $this->index = null;
        $this->calls->reset();
    }

    public function getTargets(): array
    {
        $targets = $this->targets();
        if ($targets === []) {
            throw new \LogicException('Register MacroProvider only when hasTargets() is true.');
        }

        return $targets;
    }

    public function hasTargets(): bool
    {
        return $this->targets() !== [];
    }

    /** @return list<MethodTarget> */
    private function targets(): array
    {
        $index = $this->index ??= new MacroIndex($this->root);
        if ($index->unknown) {
            return [];
        }
        $targets = [];
        foreach ($index->contracts as $class => $methods) {
            if (isset($index->blocked[$class])) {
                continue;
            }
            foreach ($methods as $name => $contract) {
                if ($contract !== null) {
                    $targets[] = MethodTarget::exact($class, $name);
                }
            }
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        return $this->resolve($context->codebase, $context->invocation, true)[0] ?? null;
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return $this->resolve($context->codebase, $context->invocation)[1] ?? null;
    }

    /** @return array{EffectiveCallableSignature, Type}|null */
    private function resolve(Codebase $codebase, Invocation $call, bool $signature = false): ?array
    {
        $name = $signature && $call->span->length() === 0
            ? $this->calls->signatureName($call)
            : $this->calls->name($call->span);
        if ($name === null || strcasecmp($name, $call->name) !== 0) {
            return null;
        }
        $receiver = $call->receiverType;
        $atom = $receiver?->atomicTypes[0] ?? null;
        if ($receiver === null || count($receiver->atomicTypes) !== 1 || ! $atom instanceof NamedObjectType) {
            return null;
        }
        $class = $atom->name;
        if ($codebase->getClassLike($class)?->directParentClass !== null) {
            return null;
        }
        $index = $this->index ??= new MacroIndex($this->root);
        if ($index->unknown || isset($index->blocked[strtolower($class)])) {
            return null;
        }
        $contract = $index->contracts[strtolower($class)][$name] ?? null;
        if ($contract instanceof MacroCallable) {
            $contract = $contract->contract($codebase);
        }
        if (
            $contract === null
            || $codebase->getMethod($class, $call->name) !== null
            || $codebase->getDeclaringMethod($class, $call->name) !== null
        ) {
            return null;
        }
        foreach ($index->conditions($class, $name) as $dependency) {
            if (
                $codebase->getClassLike($dependency)?->directParentClass !== null
                || ! $this->usesMacroable($codebase, $dependency)
                || $this->hasCatalogSubclassMutation($codebase, $index, $dependency)
            ) {
                return null;
            }
        }
        // A subclass can mutate an inherited static macro registry. Without
        // modeling property redeclarations, catalog inheritance remains ambiguous.
        if ($this->hasCatalogSubclassMutation($codebase, $index, $class)) {
            return null;
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            if ($metadata === null) {
                return null;
            }
            foreach ([...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods] as $method) {
                if (strcasecmp($method, $call->name) === 0) {
                    return null;
                }
            }
        }
        if (! $this->usesMacroable($codebase, $class)) {
            return null;
        }

        return $contract;
    }

    private function usesMacroable(Codebase $codebase, string $class): bool
    {
        // Both dispatch paths and the registry must be the actual Macroable methods.
        foreach (['macro', 'hasMacro', '__call', '__callStatic'] as $name) {
            $method = $codebase->getDeclaringMethod($class, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::TRAIT) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function hasCatalogSubclassMutation(Codebase $codebase, MacroIndex $index, string $class): bool
    {
        foreach (array_keys($index->contracts + $index->blocked) as $registeredClass) {
            if (in_array(
                strtolower($class),
                array_map(strtolower(...), $codebase->getClassAncestors($registeredClass)),
                true,
            )) {
                return true;
            }
        }

        return false;
    }
}
