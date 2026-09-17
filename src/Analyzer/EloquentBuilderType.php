<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Resolves explicit builder factory contracts without constructing a query. */
final class EloquentBuilderType
{
    public const MODEL = 'Illuminate\Database\Eloquent\Model';
    public const BUILDER = 'Illuminate\Database\Eloquent\Builder';

    public function resolve(Codebase $codebase, string $model): ?Type
    {
        $dispatch = new EloquentModelDispatch;
        foreach ([
            'query',
            'newQuery',
            'newModelQuery',
            'newQueryWithoutScopes',
            'newQueryWithoutRelationships',
            'registerGlobalScopes',
            'resolveCustomBuilderClass',
            'resolveClassAttribute',
        ] as $name) {
            if ($dispatch->overrides($codebase, $model, $name)) {
                return null;
            }
        }
        $factory = $codebase->getMethod($model, 'newEloquentBuilder') ?? $codebase->getDeclaringMethod(
            $model,
            'newEloquentBuilder',
        );
        if ($factory !== null && strcasecmp($factory->identifier->class ?? '', self::MODEL) !== 0) {
            $return = $factory->declaredReturnType->type ?? $factory->returnType?->type;
            $documented = $factory->returnType?->type;
            $documentedAtom = $documented?->atomicTypes[0] ?? null;
            $nativeAtom = $return?->atomicTypes[0] ?? null;
            if (
                $documented !== null
                && count($documented->atomicTypes) === 1
                && $documentedAtom instanceof NamedObjectType
                && ($documentedAtom->parameters ?? []) !== []
                && ($nativeAtom === null
                || $nativeAtom instanceof NamedObjectType
                && strcasecmp($nativeAtom->name, $documentedAtom->name) === 0)
            ) {
                $return = $documented;
            }
            $atom = $return?->atomicTypes[0] ?? null;
            // A factory's nested `static` refers to the model on which it is called.
            // Rebind it before checking that the resulting builder arguments are concrete.
            if ($return !== null && $atom instanceof NamedObjectType && ! $atom->static && ! $atom->isThis) {
                $return = (new EloquentBuilderGenericTypes(
                    new NamedObjectType($model, null, null, false, false, null, false),
                    [],
                ))->substitute($return);
                $atom = $return?->atomicTypes[0] ?? null;
            }
            if (
                $factory->static
                || $factory->visibility !== Visibility::Public
                || $return === null
                || count($return->atomicTypes) !== 1
                || ! $atom instanceof NamedObjectType
                || ! ExplicitGenericType::concrete($return)
                || $atom->static
            ) {
                return null;
            }

            return $this->custom($codebase, $atom->name, $atom->parameters ?? []);
        }
        $class = $codebase->getClassLike($model);
        while ($class !== null) {
            foreach ($class->attributes as $attribute) {
                if (strcasecmp($attribute->name, 'Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder') === 0) {
                    $argument = $attribute->getArgument(0, 'builderClass');
                    $name =
                        $argument?->valueType?->getLiteralClassString() ?? $argument?->valueType?->getLiteralString();

                    return $name === null ? null : $this->custom($codebase, $name);
                }
            }
            $class = $class->directParentClass === null ? null : $codebase->getClassLike($class->directParentClass);
        }
        $property = $codebase->getDeclaringProperty($model, '$builder') ?? $codebase->getProperty($model, '$builder');
        $name = $property?->defaultType?->type->getLiteralClassString() ?? $property
            ?->defaultType
            ?->type
            ->getLiteralString();

        return $name === null ? null : $this->custom($codebase, $name);
    }

    /** @param list<Type> $parameters */
    private function custom(Codebase $codebase, string $name, array $parameters = []): ?Type
    {
        if (strcasecmp($name, self::BUILDER) === 0) {
            return null;
        }
        $class = $codebase->getClassLike($name);
        if (
            $class === null
            || count($class->templates) !== count($parameters)
            || $class->flags->contains(MetadataFlags::ABSTRACT)
            || ! in_array(
                strtolower(self::BUILDER),
                array_map(strtolower(...), $codebase->getClassAncestors($name)),
                true,
            )
        ) {
            return null;
        }
        // Standard query construction chains these methods and expects the same builder.
        foreach (['setModel', 'with', 'withCount'] as $methodName) {
            $method = $codebase->getMethod($name, $methodName) ?? $codebase->getDeclaringMethod($name, $methodName);
            if ($method !== null && strcasecmp($method->identifier->class ?? '', self::BUILDER) !== 0) {
                return null;
            }
        }

        return Type::namedObject($class->originalName, ...$parameters);
    }
}
