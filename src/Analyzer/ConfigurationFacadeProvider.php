<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/** Refines literal reads through Laravel's native Config facade only. */
final class ConfigurationFacadeProvider implements MethodReturnTypeProvider
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Config';
    private const REPOSITORY = 'Illuminate\\Config\\Repository';

    private readonly NativeFacade $facade;

    public function __construct(
        string $root,
        private readonly ConfigurationProvider $configuration,
    ) {
        $this->facade = new NativeFacade($root);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FACADE, 'get')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if ($call->kind !== InvocationKind::StaticMethod) {
            return null;
        }
        if (! $this->facade->dispatches(
            $context->codebase,
            $call->receiverType,
            self::FACADE,
            'config',
            self::REPOSITORY,
            'get',
        )) {
            return null;
        }

        $result = $this->configuration->literalRead($context);
        if ($result === null) {
            return null;
        }
        $facadeMethod = $context->codebase->getMethod(self::FACADE, 'get') ?? $context->codebase->getDeclaringMethod(
            self::FACADE,
            'get',
        );
        $repositoryMethod = $context->codebase->getMethod(
            self::REPOSITORY,
            'get',
        ) ?? $context->codebase->getDeclaringMethod(self::REPOSITORY, 'get');
        if (
            ! $this->compatibleSignature($context, $facadeMethod, $result)
            || ! $this->compatibleSignature($context, $repositoryMethod, $result)
        ) {
            return null;
        }

        return $result;
    }

    private function compatibleSignature(
        ReturnTypeProviderContext $context,
        ?FunctionLikeMetadata $method,
        Type $result,
    ): bool {
        $return = $method?->returnType?->type ?? $method?->declaredReturnType?->type;
        $key = $method?->parameters[0] ?? null;
        if (
            $method === null
            || $return === null
            || $key === null
            || $key->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! $context->types->isContainedBy($result, $return)
        ) {
            return false;
        }
        $keyType = $key->type?->type ?? $key->declaredType?->type;
        $keyArgument = $context->invocation->getArgument(0, 'key');
        if (
            $keyType !== null
            && ($keyArgument?->type === null
            || ! $context->types->isContainedBy($keyArgument->type, $keyType))
        ) {
            return false;
        }
        $defaultArgument = $context->invocation->getArgument(1, 'default');
        if ($defaultArgument === null) {
            return true;
        }
        $default = $method->parameters[1] ?? null;
        $defaultType = $default?->type?->type ?? $default?->declaredType?->type;

        return (
            $default !== null
            && ! $default->flags->contains(MetadataFlags::BY_REFERENCE)
            && (
                $defaultType === null
                || $defaultArgument->type !== null
                && $context->types->isContainedBy($defaultArgument->type, $defaultType)
            )
        );
    }
}
