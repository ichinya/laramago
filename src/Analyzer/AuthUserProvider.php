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
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Resolves conventional authentication users without executing configuration or application code. */
final class AuthUserProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const REQUEST = 'Illuminate\\Http\\Request';
    private const MANAGER = 'Illuminate\\Auth\\AuthManager';
    private const FACADE = 'Illuminate\\Support\\Facades\\Auth';

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
            MethodTarget::exact(self::REQUEST, 'user'),
            MethodTarget::exact(self::MANAGER, 'user'),
            MethodTarget::exact('Illuminate\\Contracts\\Auth\\Guard', 'user'),
            MethodTarget::exact('Illuminate\\Contracts\\Auth\\StatefulGuard', 'user'),
            MethodTarget::exact(self::FACADE, 'user'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $atoms = $call->receiverType?->atomicTypes ?? [];
        if (count($atoms) !== 1 || ! $atoms[0] instanceof NamedObjectType) {
            return null;
        }
        $name = $atoms[0]->name;
        if (in_array($name, [self::MANAGER, self::FACADE], true)) {
            // A concrete method on a replacement framework declaration remains authoritative.
            $class = $context->codebase->getClass($name);
            if ($class === null || $call->arguments !== []) {
                return null;
            }
            $method = $context->codebase->getMethod($name, 'user');
            if (
                $name === self::FACADE
                && $method === null
                || $method !== null
                && ! $method->flags->contains(MetadataFlags::MAGIC_METHOD)
            ) {
                return null;
            }
            $method ??= $context->codebase->getMethod($call->declaringClass ?? '', 'user');
            $return = $method?->returnType?->type;
            if ($return === null) {
                return null;
            }
            $contract = Type::union(Type::namedObject('Illuminate\\Contracts\\Auth\\Authenticatable'), Type::null());
            if (
                ! $context->types->isContainedBy($return, $contract)
                || ! $context->types->isContainedBy($contract, $return)
            ) {
                return null;
            }
        } else {
            $method = $context->codebase->getMethod($name, 'user') ?? $context->codebase->getDeclaringMethod(
                $name,
                'user',
            );
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::REQUEST) !== 0) {
                return null;
            }
            $contract = Type::union(Type::namedObject('Illuminate\\Contracts\\Auth\\Authenticatable'), Type::null());
            $return = $method->returnType?->type;
            $atoms = $return?->atomicTypes ?? [];
            $standardMixed = count($atoms) === 1 && $atoms[0] instanceof MixedType;
            if (
                $return === null
                || ! $standardMixed
                && (! $context->types->isContainedBy($return, $contract)
                || ! $context->types->isContainedBy($contract, $return))
            ) {
                return null;
            }
            $resolver = $context->codebase->getMethod(
                $name,
                'getUserResolver',
            ) ?? $context->codebase->getDeclaringMethod($name, 'getUserResolver');
            if ($resolver !== null && strcasecmp($resolver->identifier->class ?? '', self::REQUEST) !== 0) {
                return null;
            }
            foreach ($context->codebase->getMultipleClasses([
                $name,
                ...$context->codebase->getClassAncestors($name),
            ]) as $metadata) {
                foreach ($metadata->pseudoMethods ?? [] as $pseudo) {
                    if (strcasecmp($pseudo, 'user') === 0) {
                        return null;
                    }
                }
            }
        }
        $configuration = $this->configuration ??= new AuthConfiguration($this->root);

        return $configuration->userType($configuration->guardName($call, 'guard'), $context);
    }
}
