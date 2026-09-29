<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;

/** Preserve sealed record shapes when PHP replaces their known keys. */
final class ArrayReplaceProvider implements FunctionReturnTypeProvider
{
    public function getTargets(): array
    {
        return [FunctionTarget::exact('array_replace')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if ($call->kind !== InvocationKind::Function || $call->arguments === []) {
            return null;
        }
        $items = [];
        foreach ($call->arguments as $argument) {
            $type = $argument->type;
            $array = $type?->atomicTypes[0] ?? null;
            if (
                $argument->name !== null || $argument->unpacked || $argument->placeholder
                || $type === null || count($type->atomicTypes) !== 1
                || ! $array instanceof KeyedArrayType
                || $array->keyType !== null || $array->valueType !== null
            ) {
                return null;
            }
            foreach ($array->knownItems ?? [] as $item) {
                if ($item->optional || $item->key->kind === ArrayKeyKind::ClassLikeConstant
                    || is_string($item->key->value) && is_numeric($item->key->value)) {
                    return null;
                }
                $key = $item->key->kind->name.':'.$item->key->value;
                // Replacing an existing key preserves its position, including
                // numeric keys. Replacement is shallow, never recursive.
                $items[$key] = $item;
            }
        }

        return Type::fromAtomic(new KeyedArrayType(array_values($items), null, null, $items !== []));
    }
}
