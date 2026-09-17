<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\PropertyAccess;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Span;

/** Read a literal item property only when every input branch has a known contract. */
final class CollectionItemProperty
{
    public function __construct(
        private readonly EloquentPropertyProvider $properties,
    ) {}

    public function resolve(
        Type $items,
        string $name,
        PropertyTypeProviderContext|ReturnTypeProviderContext $context,
        Span $span,
        bool $dataGet = false,
    ): ?Type {
        if ($name === '' || str_contains($name, '.') || str_contains($name, '*')) {
            return null;
        }
        $result = null;
        foreach ($items->atomicTypes as $atom) {
            $type = null;
            if ($atom instanceof KeyedArrayType) {
                foreach ($atom->knownItems ?? [] as $item) {
                    if ((string) $item->key->value === $name) {
                        if ($item->optional && ! $dataGet) {
                            return null;
                        }
                        $type = $item->optional ? Type::union($item->type, Type::null()) : $item->type;
                        break;
                    }
                }
                // data_get returns its null default for absent array keys.
                if ($type === null && $dataGet) {
                    $type = $atom->valueType === null ? Type::null() : Type::union($atom->valueType, Type::null());
                }
            } elseif ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null && $dataGet) {
                $type = Type::null();
            } elseif ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Never) {
                $type = Type::never();
            } elseif ($atom instanceof ListType && ctype_digit($name) && (string) (int) $name === $name) {
                foreach ($atom->knownElements ?? [] as $element) {
                    if ($element->index === (int) $name) {
                        if ($element->optional && ! $dataGet) {
                            return null;
                        }
                        $type = $element->optional ? Type::union($element->type, Type::null()) : $element->type;
                        break;
                    }
                }
                if ($type === null && $dataGet) {
                    $type = Type::union($atom->elementType, Type::null());
                }
            } elseif (
                $atom instanceof NamedObjectType
                && ($atom->parameters ?? []) === []
                && ($atom->intersections ?? []) === []
            ) {
                $property = $context->codebase->getDeclaringProperty(
                    $atom->name,
                    '$'.$name,
                ) ?? $context->codebase->getDeclaringMagicProperty($atom->name, '$'.$name);
                if ($property !== null) {
                    if (
                        $property->readVisibility !== Visibility::Public
                        || $property->flags->contains(MetadataFlags::STATIC)
                        || $property->flags->contains(MetadataFlags::WRITEONLY)
                    ) {
                        return null;
                    }
                    $type = $property->type?->type ?? $property->declaredType?->type;
                } elseif ($context->types->isContainedBy(
                    Type::fromAtomic($atom),
                    Type::namedObject('Illuminate\\Database\\Eloquent\\Model'),
                )) {
                    $type = $this->properties->getPropertyType(new PropertyTypeProviderContext(
                        $context->phpVersion,
                        $context->codebase,
                        new PropertyAccess(
                            $atom->name,
                            $name,
                            PropertyAccessKind::Read,
                            Type::fromAtomic($atom),
                            $span,
                        ),
                        $context->types,
                        $context->cancellation,
                    ))?->readType;
                }
            }
            if ($type === null || ! self::concrete($type)) {
                return null;
            }
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }

    public static function concrete(Type $type): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof KeyedArrayType) {
                if ($atom->valueType !== null && ! self::concrete($atom->valueType)) {
                    return false;
                }
                foreach ($atom->knownItems ?? [] as $item) {
                    if (! self::concrete($item->type)) {
                        return false;
                    }
                }
            } elseif ($atom instanceof ListType) {
                if (! self::concrete($atom->elementType)) {
                    return false;
                }
                foreach ($atom->knownElements ?? [] as $element) {
                    if (! self::concrete($element->type)) {
                        return false;
                    }
                }
            } elseif (! ExplicitGenericType::concrete(Type::fromAtomic($atom))) {
                return false;
            }
        }

        return true;
    }
}
