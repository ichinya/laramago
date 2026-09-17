<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ConditionalType;

/** Exposes the framework manager and literal standard guards through their native contracts. */
final class AuthHelperProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private ?AuthConfiguration $configuration = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->configuration = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('auth')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $configuration = $this->configuration ??= new AuthConfiguration($this->root);
        if ($configuration->hasCustomFactory()) {
            return null;
        }
        $function = $context->codebase->getFunction('auth');
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        if (! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')) {
            return null;
        }
        $factory = Type::namedObject('Illuminate\\Contracts\\Auth\\Factory');
        $guard = Type::namedObject('Illuminate\\Contracts\\Auth\\Guard');
        $expected = Type::union($factory, $guard);
        $declared = $function?->declaredReturnType?->type;
        if ($declared !== null && ! $this->sameType($declared, $expected, $context)) {
            return null;
        }
        $effective = $function?->returnType?->type;
        $atom = $effective?->atomicTypes[0] ?? null;
        if ($atom instanceof ConditionalType && count($effective?->atomicTypes ?? []) === 1) {
            if (
                $atom->negated
                || (string) $atom->target !== 'null'
                || ! $this->sameType($atom->then, $factory, $context)
                || ! $this->sameType($atom->otherwise, $guard, $context)
            ) {
                return null;
            }
        } elseif ($effective === null || ! $this->sameType($effective, $expected, $context)) {
            return null;
        }
        $call = $context->invocation;
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $argument = $call->getArgument(0, 'guard');
        if ($argument === null || (string) $argument->type === 'null') {
            $class = 'Illuminate\\Auth\\AuthManager';
        } else {
            $class = $configuration->guardClass($configuration->guardName($call, 'guard'), $context);
        }

        return $class !== null && $context->codebase->getClass($class) !== null ? Type::namedObject($class) : null;
    }

    private function sameType(Type $left, Type $right, ReturnTypeProviderContext $context): bool
    {
        return $context->types->isContainedBy($left, $right) && $context->types->isContainedBy($right, $left);
    }
}
