<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider, FunctionTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, MetadataFlags};
use Mago\Sdk\Analyzer\Type\{EnumType, KeyedArrayType, ListType, NamedObjectType};

/** A value column of known backed enums is a reindexed list of backing scalars. */
final class BackedEnumColumnProvider implements FunctionReturnTypeProvider
{
    public function getTargets(): array { return [FunctionTarget::exact('array_column')]; }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if (count($call->arguments) < 2 || count($call->arguments) > 3) { return null; }
        foreach ($call->arguments as $argument) {
            if ($argument->placeholder || $argument->unpacked || $argument->name !== null
                && ! in_array($argument->name, ['array', 'column_key', 'index_key'], true)) { return null; }
        }
        if ($call->getArgument(1, 'column_key')?->type?->getLiteralString() !== 'value') { return null; }
        $index = $call->getArgument(2, 'index_key');
        if ($index !== null && (string) $index->type !== 'null') { return null; }
        $native = $context->codebase->getFunction('array_column');
        if ($native === null || ! $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::USER_DEFINED)) { return null; }
        $array = $call->getArgument(0, 'array')?->type;
        if ($array === null) { return null; }
        $values = [];
        foreach ($array->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) { $values[] = $atomic->elementType; }
            elseif ($atomic instanceof KeyedArrayType) {
                if ($atomic->valueType !== null) { $values[] = $atomic->valueType; }
                foreach ($atomic->knownItems ?? [] as $item) { $values[] = $item->type; }
            } else { return null; }
        }
        $backing = Type::never();
        foreach ($values as $value) {
            foreach ($value->atomicTypes as $enum) {
                if (! $enum instanceof NamedObjectType && ! $enum instanceof EnumType) { return null; }
                $class = $context->codebase->getClassLike($enum->name);
                if ($class === null || $class->kind !== ClassLikeKind::Enum || $class->enumType === null) { return null; }
                $backing = Type::union($backing, $class->enumType);
            }
        }
        return Type::list($backing);
    }
}
