<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Resolve standard collections and explicit custom collection factory contracts. */
final class EloquentCollectionType
{
    private const MODEL = 'Illuminate\Database\Eloquent\Model';
    private const COLLECTION = 'Illuminate\Database\Eloquent\Collection';
    private const TRAIT = 'Illuminate\Database\Eloquent\HasCollection';

    public function resolve(Codebase $codebase, Type $model): ?Type
    {
        $result = null;
        $standard = true;
        foreach ($model->atomicTypes as $atom) {
            if (! $atom instanceof NamedObjectType || ! $this->inherits($codebase, $atom->name, self::MODEL)) {
                return null;
            }
            $name = $this->collectionClass($codebase, $atom->name);
            if ($name === null) {
                return null;
            }
            if ($name instanceof Type) {
                $standard = false;
                $result = $result === null ? $name : Type::union($result, $name);
                continue;
            }
            $isStandard = strcasecmp($name, self::COLLECTION) === 0;
            $standard = $standard && $isStandard;
            $type = $isStandard
                ? Type::namedObject(self::COLLECTION, Type::int(), Type::fromAtomic($atom))
                : Type::namedObject($name);
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $standard ? Type::namedObject(self::COLLECTION, Type::int(), $model) : $result;
    }

    private function collectionClass(Codebase $codebase, string $model): Type|string|null
    {
        $method = $codebase->getMethod($model, 'newCollection') ?? $codebase->getDeclaringMethod(
            $model,
            'newCollection',
        );
        if (
            $method !== null
            && ! in_array(
                strtolower($method->identifier->class ?? ''),
                [strtolower(self::MODEL), strtolower(self::TRAIT)],
                true,
            )
        ) {
            if ($method->static || $method->visibility !== Visibility::Public) {
                return null;
            }
            $return = $method->returnType->type ?? $method->declaredReturnType?->type;
            $atom = $return?->atomicTypes[0] ?? null;
            if (
                $return === null
                || count($return->atomicTypes) !== 1
                || ! $atom instanceof NamedObjectType
                || ! ExplicitGenericType::concrete($return)
                || $atom->static
            ) {
                return null;
            }

            $parameters = $atom->parameters ?? [];
            $name = $this->customClass($codebase, $atom->name, count($parameters));

            return $name === null ? null : Type::namedObject($name, ...$parameters);
        }
        $resolver = $codebase->getMethod($model, 'resolveCollectionFromAttribute') ?? $codebase->getDeclaringMethod(
            $model,
            'resolveCollectionFromAttribute',
        );
        if (
            $resolver !== null
            && ! in_array(
                strtolower($resolver->identifier->class ?? ''),
                [strtolower(self::MODEL), strtolower(self::TRAIT)],
                true,
            )
        ) {
            return null;
        }
        // Laravel checks attributes on the nearest class first, then its parents.
        $class = $codebase->getClassLike($model);
        while ($class !== null) {
            foreach ($class->attributes as $attribute) {
                if (strcasecmp($attribute->name, 'Illuminate\Database\Eloquent\Attributes\CollectedBy') === 0) {
                    // HasCollection reads positional argument zero, not named arguments.
                    $argument = $attribute->getArgument(0);
                    $name =
                        $argument?->valueType?->getLiteralClassString() ?? $argument?->valueType?->getLiteralString();

                    return $name === null ? null : $this->customClass($codebase, $name);
                }
            }
            $class = $class->directParentClass === null ? null : $codebase->getClassLike($class->directParentClass);
        }
        $property = $codebase->getDeclaringProperty($model, '$collectionClass') ?? $codebase->getProperty(
            $model,
            '$collectionClass',
        );
        if ($property === null) {
            return self::COLLECTION;
        }
        $name = $property->defaultType?->type->getLiteralClassString() ?? $property
            ->defaultType
            ?->type
            ->getLiteralString();

        return $name === null || strcasecmp($name, self::COLLECTION) === 0
            ? $name
            : $this->customClass($codebase, $name);
    }

    private function customClass(Codebase $codebase, string $name, int $parameterCount = 0): ?string
    {
        $class = $codebase->getClassLike($name);
        if (
            $class === null
            || count($class->templates) !== $parameterCount
            || $class->flags->contains(MetadataFlags::ABSTRACT)
            || ! $this->inherits($codebase, $name, self::COLLECTION)
        ) {
            return null;
        }

        return $class->originalName;
    }

    private function inherits(Codebase $codebase, string $class, string $parent): bool
    {
        return (
            strcasecmp($class, $parent) === 0
            || in_array(strtolower($parent), array_map(strtolower(...), $codebase->getClassAncestors($class)), true)
        );
    }
}
