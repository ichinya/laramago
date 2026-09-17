<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\StringType;

/** Substitutes only explicit bindings owned by the selected builder class. */
final class EloquentBuilderGenericTypes
{
    /** @param array<string, Type> $bindings */
    public function __construct(
        private readonly NamedObjectType $builder,
        private readonly array $bindings,
    ) {}

    public function substitute(Type $type): ?Type
    {
        $parts = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof GenericParameterType) {
                if (
                    $atom->definingEntity->kind !== GenericParentKind::ClassLike
                    || strcasecmp($atom->definingEntity->name, $this->builder->name) !== 0
                    || ($atom->intersections ?? []) !== []
                    || ! isset($this->bindings[$atom->name])
                ) {
                    return null;
                }
                $parts[] = $this->bindings[$atom->name];
                continue;
            }
            if ($atom instanceof NamedObjectType) {
                if (($atom->intersections ?? []) !== []) {
                    return null;
                }
                if ($atom->static || $atom->isThis) {
                    $parts[] = Type::fromAtomic($this->builder);
                    continue;
                }
                $parameters = [];
                foreach ($atom->parameters ?? [] as $parameter) {
                    $resolved = $this->substitute($parameter);
                    if ($resolved === null) {
                        return null;
                    }
                    $parameters[] = $resolved;
                }
                $atom = new NamedObjectType(
                    $atom->name,
                    $atom->parameters === null ? null : $parameters,
                    $atom->variances,
                    false,
                    false,
                    null,
                    $atom->remappedParameters,
                );
            } elseif ($atom instanceof ListType) {
                $element = $this->substitute($atom->elementType);
                if ($element === null) {
                    return null;
                }
                $elements = [];
                foreach ($atom->knownElements ?? [] as $item) {
                    $resolved = $this->substitute($item->type);
                    if ($resolved === null) {
                        return null;
                    }
                    $elements[] = new ListElement($item->index, $item->optional, $resolved);
                }
                $atom = new ListType(
                    $element,
                    $atom->knownElements === null ? null : $elements,
                    $atom->knownCount,
                    $atom->nonEmpty,
                );
            } elseif ($atom instanceof KeyedArrayType) {
                $key = $atom->keyType === null ? null : $this->substitute($atom->keyType);
                $value = $atom->valueType === null ? null : $this->substitute($atom->valueType);
                if ($atom->keyType !== null && $key === null || $atom->valueType !== null && $value === null) {
                    return null;
                }
                $items = [];
                foreach ($atom->knownItems ?? [] as $item) {
                    $resolved = $this->substitute($item->type);
                    if ($resolved === null) {
                        return null;
                    }
                    $items[] = new ArrayItem($item->key, $item->optional, $resolved);
                }
                $atom = new KeyedArrayType($atom->knownItems === null ? null : $items, $key, $value, $atom->nonEmpty);
            } elseif (
                ! $atom instanceof IntegerType
                && ! $atom instanceof MixedType
                && ! $atom instanceof ScalarType
                && ! $atom instanceof StringType
                && ! $atom instanceof SimpleAtomicType
            ) {
                // Non-generic declarations already have a complete SDK type.
                // With class bindings, unfamiliar containers may hide templates.
                if ($this->bindings !== []) {
                    return null;
                }
            }
            $parts[] = Type::fromAtomic($atom);
        }
        $first = array_shift($parts);

        return $first === null
            ? null
            : array_reduce($parts, static fn (Type $a, Type $b): Type => Type::union($a, $b), $first);
    }
}
