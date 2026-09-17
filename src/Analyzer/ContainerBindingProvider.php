<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerNativeContract;
use Mago\Sdk\Analyzer\Argument;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Refines native container make() calls through explicit static binding catalogs. */
final class ContainerBindingProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const CONTRACT = 'Illuminate\\Contracts\\Container\\Container';

    private ?ContainerBindings $bindings = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->bindings = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::CONTRACT, 'make')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $object instanceof NamedObjectType
            || ! $this->nativeMake($object->name, $context)
        ) {
            return null;
        }

        return $this->bindingType($call->getArgument(0, 'abstract'), $context);
    }

    private function nativeMake(string $class, ReturnTypeProviderContext $context): bool
    {
        foreach ($context->codebase->getMultipleClasses([
            $class,
            ...$context->codebase->getClassAncestors($class),
        ]) as $metadata) {
            foreach ([...($metadata?->pseudoMethods ?? []), ...($metadata?->staticPseudoMethods ?? [])] as $method) {
                if (strcasecmp($method, 'make') === 0) {
                    return false;
                }
            }
        }
        $method = $context->codebase->getDeclaringMethod($class, 'make');
        $file = str_replace('\\', '/', $method?->location->file ?? '');

        return (
            $method !== null
            && ! $method->static
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE)
            && array_filter(
                $method->parameters,
                static fn ($parameter): bool => $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
            ) === []
            && ContainerNativeContract::make($method)
            && (
                self::frameworkFile($file, 'Illuminate/Container/Container.php')
                || self::frameworkFile($file, 'Illuminate/Foundation/Application.php')
                || self::frameworkFile($file, 'Illuminate/Contracts/Container/Container.php')
            )
        );
    }

    private function bindingType(?Argument $argument, ReturnTypeProviderContext $context): ?Type
    {
        if ($argument === null || $argument->unpacked || $argument->placeholder || $argument->type === null) {
            return null;
        }
        $abstract = $argument->type->getLiteralClassString() ?? $argument->type->getLiteralString();
        if ($abstract === null) {
            return null;
        }
        $bindings = $this->bindings ??= new ContainerBindings($this->root);
        if (! $bindings->configured($abstract)) {
            return null;
        }
        $concrete = $bindings->concrete($abstract, $context->codebase);

        return $concrete === null ? null : Type::namedObject($concrete);
    }

    private static function frameworkFile(string $file, string $suffix): bool
    {
        return str_ends_with($file, '/laravel/framework/src/'.$suffix);
    }
}
