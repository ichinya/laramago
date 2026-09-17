<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Reads concrete caster contracts; never constructs or invokes a caster. */
final class CustomCastTypes
{
    private const CONTRACT = 'illuminate\\contracts\\database\\eloquent\\castsattributes';
    private const INBOUND = 'illuminate\\contracts\\database\\eloquent\\castsinboundattributes';
    private const CASTABLE = 'illuminate\\contracts\\database\\eloquent\\castable';

    public static function resolve(
        string $cast,
        Codebase $codebase,
        ?Column $column,
        ?PhpSource $source = null,
    ): ?PropertyType {
        $class = explode(':', $cast, 2)[0];
        $metadata = $codebase->getClassLike($class);
        if (
            $metadata === null
            || $metadata->kind !== ClassLikeKind::Class_
            || $metadata->flags->contains(MetadataFlags::ABSTRACT)
        ) {
            return null;
        }
        $parents = array_map(strtolower(...), $codebase->getClassAncestors($class));
        if (in_array(self::CASTABLE, $parents, true)) {
            $target = $source === null ? null : CastableTarget::resolve($class, $codebase, $source);
            if ($target === null || strcasecmp($target, $class) === 0) {
                return null;
            }

            // Resolve one concrete caster, never recursively invoke Castable factories.
            return self::resolve($target, $codebase, $column);
        }
        $inbound = in_array(self::INBOUND, $parents, true);
        if (in_array(self::CASTABLE, $parents, true) || ! $inbound && ! in_array(self::CONTRACT, $parents, true)) {
            return null;
        }
        $setter = self::method($codebase, $class, 'set');
        $parameter = $setter->parameters[2] ?? null;
        $write = $parameter->type->type ?? $parameter?->declaredType?->type;
        if ($write === null || self::unresolved($write)) {
            return null;
        }
        $getter = self::method($codebase, $class, 'get');
        // Inbound casts leave the stored value unchanged when reading.
        $read = $getter->returnType->type ?? $getter?->declaredReturnType?->type;
        if ($inbound) {
            $read = $column === null ? null : AttributeTypes::column($column)->readType;
        }
        if ($read === null || self::unresolved($read) || (string) $read === 'void') {
            return null;
        }

        // Laravel invokes class casts for null too. The methods, not the column,
        // determine whether the transformed value or accepted input is nullable.
        return new PropertyType($read, $write);
    }

    private static function method(Codebase $codebase, string $class, string $name): ?FunctionLikeMetadata
    {
        $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);

        return $method === null || $method->static || $method->abstract || $method->visibility !== Visibility::Public
            ? null
            : $method;
    }

    /** Unsubstituted templates must never escape into the model's property type. */
    private static function unresolved(mixed $value): bool
    {
        if (
            $value instanceof GenericParameterType
            || $value instanceof ConditionalType
            || $value instanceof AliasType
        ) {
            return true;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        $children = is_array($value) ? $value : [];

        return array_filter($children, self::unresolved(...)) !== [];
    }
}
