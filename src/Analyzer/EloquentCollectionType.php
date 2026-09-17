<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Resolve the standard model collection without overriding custom collection contracts. */
final class EloquentCollectionType
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';

    public function resolve(Codebase $codebase, Type $model): ?Type
    {
        foreach ($model->atomicTypes as $atom) {
            if (! $atom instanceof NamedObjectType || ! $this->inherits($codebase, $atom->name)) {
                return null;
            }
            foreach (['newCollection', 'resolveCollectionFromAttribute'] as $name) {
                $method = $codebase->getMethod($atom->name, $name) ?? $codebase->getDeclaringMethod($atom->name, $name);
                if (
                    $method !== null
                    && strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0
                    && strcasecmp($method->identifier->class ?? '', 'Illuminate\\Database\\Eloquent\\HasCollection')
                        !== 0
                ) {
                    return null;
                }
            }
            $collection = $codebase->getDeclaringProperty($atom->name, '$collectionClass') ?? $codebase->getProperty(
                $atom->name,
                '$collectionClass',
            );
            if (
                $collection !== null
                && strcasecmp($collection->defaultType?->type->getLiteralClassString() ?? '', self::COLLECTION) !== 0
            ) {
                return null;
            }
            foreach ($codebase->getMultipleClasses([
                $atom->name,
                ...$codebase->getClassAncestors($atom->name),
            ]) as $class) {
                foreach ($class->attributes ?? [] as $attribute) {
                    if (strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\CollectedBy') === 0) {
                        return null;
                    }
                }
            }
        }

        return Type::namedObject(self::COLLECTION, Type::int(), $model);
    }

    private function inherits(Codebase $codebase, string $class): bool
    {
        return (
            strcasecmp($class, self::MODEL) === 0
            || in_array(strtolower(self::MODEL), array_map(strtolower(...), $codebase->getClassAncestors($class)), true)
        );
    }
}
