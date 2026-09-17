<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Narrows only the framework manager/facade guard factory, never application implementations. */
final class AuthGuardProvider implements MethodReturnTypeProvider, InitializationHook
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
        return [
            MethodTarget::exact('Illuminate\\Auth\\AuthManager', 'guard'),
            MethodTarget::exact('Illuminate\\Support\\Facades\\Auth', 'guard'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $atoms = $context->invocation->receiverType?->atomicTypes ?? [];
        if (count($atoms) !== 1 || ! $atoms[0] instanceof NamedObjectType) {
            return null;
        }
        $name = $atoms[0]->name;
        if (! in_array($name, ['Illuminate\\Auth\\AuthManager', 'Illuminate\\Support\\Facades\\Auth'], true)) {
            return null;
        }
        $class = $context->codebase->getClass($name);
        if ($class === null) {
            return null;
        }
        $method = $context->codebase->getMethod($name, 'guard');
        if (
            $name === 'Illuminate\\Support\\Facades\\Auth'
            && ! ($method?->flags->contains(MetadataFlags::MAGIC_METHOD) ?? false)
        ) {
            return null;
        }
        $return = $method?->returnType?->type;
        $contract = Type::namedObject('Illuminate\\Contracts\\Auth\\Guard');
        if (
            $return === null
            || ! $context->types->isContainedBy($return, $contract)
            || ! $context->types->isContainedBy($contract, $return)
        ) {
            return null;
        }
        $configuration = $this->configuration ??= new AuthConfiguration($this->root);
        $guard = $configuration->guardClass($configuration->guardName($context->invocation, 'name'), $context);

        return $guard !== null && $context->codebase->getClass($guard) !== null ? Type::namedObject($guard) : null;
    }
}
