<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider, FunctionTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\{KeyedArrayType, ListType, SimpleAtomicType, SimpleAtomicTypeKind};

/** A null column key keeps each array row, including unions of row shapes. */
final class ArrayColumnRowsProvider implements FunctionReturnTypeProvider
{
    public function getTargets(): array { return [FunctionTarget::exact('array_column')]; }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if (count($call->arguments) < 2 || count($call->arguments) > 3) { return null; }
        $names = [];
        foreach ($call->arguments as $argument) {
            if ($argument->placeholder || $argument->unpacked
                || $argument->name !== null && (! in_array($argument->name, ['array', 'column_key', 'index_key'], true)
                    || isset($names[$argument->name]))) { return null; }
            if ($argument->name !== null) { $names[$argument->name] = true; }
        }
        $column = $call->getArgument(1, 'column_key')?->type;
        if (! self::isNull($column)) { return null; }
        $index = $call->getArgument(2, 'index_key');
        $reindexed = $index === null || self::isNull($index->type);
        if (! $reindexed && ($index->type === null
            || $index->type->getLiteralString() === null && $index->type->getLiteralInt() === null)) { return null; }
        $native = $context->codebase->getFunction('array_column');
        if ($native === null || $native->identifier->class !== null
            || strcasecmp($native->identifier->name, 'array_column') !== 0
            || ! $native->flags->contains(MetadataFlags::BUILTIN)
            || $native->flags->contains(MetadataFlags::USER_DEFINED)) { return null; }
        $array = $call->getArgument(0, 'array')?->type;
        if ($array === null || count($array->atomicTypes) > 64) { return null; }
        $rows = [];
        foreach ($array->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) {
                $rows[] = $atomic->elementType;
            } elseif ($atomic instanceof KeyedArrayType) {
                if ($atomic->valueType !== null) { $rows[] = $atomic->valueType; }
                foreach ($atomic->knownItems ?? [] as $item) { $rows[] = $item->type; }
            } else { return null; }
            if (count($rows) > 64) { return null; }
        }
        if ($rows === []) { return null; }
        $unionRows = false;
        foreach ($rows as $row) {
            if ($row->atomicTypes === [] || count($row->atomicTypes) > 64) { return null; }
            $unionRows = $unionRows || count($row->atomicTypes) > 1;
            foreach ($row->atomicTypes as $atomic) {
                // Objects and scalar entries have different array_column semantics.
                if (! $atomic instanceof KeyedArrayType && ! $atomic instanceof ListType) { return null; }
            }
        }
        // Preserve native precision outside the reproduced union-row failure.
        if (! $unionRows) { return null; }
        $value = array_shift($rows);
        foreach ($rows as $row) { $value = Type::union($value, $row); }

        return $reindexed ? Type::list($value) : Type::array(Type::union(Type::int(), Type::string()), $value);
    }

    private static function isNull(?Type $type): bool
    {
        return $type !== null && count($type->atomicTypes) === 1
            && $type->atomicTypes[0] instanceof SimpleAtomicType
            && $type->atomicTypes[0]->kind === SimpleAtomicTypeKind::Null;
    }
}
