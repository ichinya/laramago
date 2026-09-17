<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Resolves only concrete, documented non-polymorphic relationship targets. */
final class EloquentRelationCallbackType
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    public function related(Codebase $codebase, Type $model, string $path, string $methodName): ?Type
    {
        $dispatch = new EloquentModelDispatch;
        $related = $model;
        foreach (explode('.', $path) as $name) {
            $class = $related->atomicTypes[0];
            if (! $class instanceof NamedObjectType) {
                return null;
            }
            $method = $codebase->getMethod($class->name, $name) ?? $codebase->getDeclaringMethod($class->name, $name);
            if (
                $method === null
                || $method->static
                || $method->visibility !== Visibility::Public
                || $method->parameters !== []
            ) {
                return null;
            }
            $return = $method->returnType->type ?? $method->declaredReturnType?->type;
            $relation = $return !== null && count($return->atomicTypes) === 1 ? $return->atomicTypes[0] : null;
            if (
                ! $relation instanceof NamedObjectType
                || ! in_array(
                    $relation->name,
                    array_map(
                        static fn (string $kind): string => 'Illuminate\\Database\\Eloquent\\Relations\\'.$kind,
                        [
                            'HasMany',
                            'HasOne',
                            'BelongsTo',
                            'BelongsToMany',
                            'HasManyThrough',
                            'HasOneThrough',
                            'MorphMany',
                            'MorphOne',
                            'MorphToMany',
                        ],
                    ),
                    true,
                )
            ) {
                return null;
            }
            $related = $relation->parameters[0] ?? null;
            if (
                $related === null
                || count($related->atomicTypes) !== 1
                || ! $related->atomicTypes[0] instanceof NamedObjectType
                || ! in_array(
                    strtolower(self::MODEL),
                    array_map(strtolower(...), $codebase->getClassAncestors($related->atomicTypes[0]->name)),
                    true,
                )
            ) {
                return null;
            }
            if (! $dispatch->supportsModel($codebase, $related->atomicTypes[0]->name, $methodName)) {
                return null;
            }
        }

        return $related;
    }
}
