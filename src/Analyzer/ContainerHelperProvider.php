<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerNativeContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\FrameworkContainerAliases;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/** Refines Laravel app() and resolve() through static binding catalogs and installed core aliases. */
final class ContainerHelperProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private ?ContainerBindings $bindings = null;
    private ?FrameworkContainerAliases $coreAliases = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->bindings = null;
        $this->coreAliases = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('app'), FunctionTarget::exact('resolve')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $name = strtolower($call->name);
        $function = $context->codebase->getFunction($name);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        if (
            $function === null
            || $function->flags->contains(MetadataFlags::BY_REFERENCE)
            || array_filter(
                $function->parameters,
                static fn ($parameter): bool => $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
            ) !== []
            || ! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')
            || ! ContainerNativeContract::helper($function, $name)
        ) {
            return null;
        }
        $argument = $call->getArgument(0, $name === 'app' ? 'abstract' : 'name');
        if ($argument === null || $argument->unpacked || $argument->placeholder || $argument->type === null) {
            return null;
        }
        foreach ($call->arguments as $candidate) {
            if ($candidate->unpacked || $candidate->placeholder) {
                return null;
            }
        }
        $abstract = $argument->type->getLiteralClassString() ?? $argument->type->getLiteralString();
        if ($abstract === null) {
            return null;
        }
        $bindings = $this->bindings ??= new ContainerBindings($this->root);
        $concrete = $bindings->configured($abstract)
            ? $bindings->concrete($abstract, $context->codebase)
            : ($this->coreAliases ??= new FrameworkContainerAliases(
                new PhpSource($this->root),
            ))->concrete($context->codebase, $abstract);

        return $concrete === null ? null : Type::namedObject($concrete);
    }
}
