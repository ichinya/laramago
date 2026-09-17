<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

/** Refine whole-value filtering without executing collection callbacks. */
final class CollectionFilterProvider implements MethodReturnTypeProvider
{
    private const COLLECTION = 'Illuminate\\Support\\Collection';

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::COLLECTION, 'filter'), MethodTarget::exact(self::COLLECTION, 'whereNotNull')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        if ($receiver === null || count($receiver->atomicTypes) !== 1) {
            return null;
        }
        $atom = $receiver->atomicTypes[0];
        if (
            ! $atom instanceof NamedObjectType
            || $atom->name !== self::COLLECTION
            || count($atom->parameters ?? []) !== 2
        ) {
            return null;
        }
        $keyType = $atom->parameters[0] ?? null;
        $valueType = $atom->parameters[1] ?? null;
        if ($keyType === null || $valueType === null) {
            return null;
        }
        // Subclass overrides, callbacks, keyed filtering and unpacked arguments retain native contracts.
        if (count($call->arguments) > 1) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            $expectedName = strtolower($call->name) === 'filter' ? 'callback' : 'key';
            if (
                $argument->unpacked
                || $argument->placeholder
                || $argument->name !== null
                && $argument->name !== $expectedName
            ) {
                return null;
            }
            $type = $argument->type;
            if (
                $type === null
                || count($type->atomicTypes) !== 1
                || ! $type->atomicTypes[0] instanceof SimpleAtomicType
                || $type->atomicTypes[0]->kind !== SimpleAtomicTypeKind::Null
            ) {
                return null;
            }
        }
        if (
            $context->codebase->getDeclaringMethod(self::COLLECTION, $call->name) === null
            && $context->codebase->getMethod(self::COLLECTION, $call->name) === null
        ) {
            return null;
        }
        $values = [];
        foreach ($valueType->atomicTypes as $value) {
            if ($value instanceof SimpleAtomicType && $value->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }
            if (
                strtolower($call->name) === 'filter'
                && $value instanceof ScalarType
                && $value->kind === ScalarTypeKind::Boolean
            ) {
                if ($value->refinement !== false) {
                    $values[] = Type::true()->atomicTypes[0];
                }
                continue;
            }
            $values[] = $value;
        }
        $filtered = $values === [] ? Type::never() : Type::fromAtomics(...$values);

        return Type::namedObject(self::COLLECTION, $keyType, $filtered);
    }
}
