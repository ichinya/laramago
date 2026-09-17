<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Map known model properties without losing the collection's keys or value contract. */
final class HigherOrderMapPropertyProvider implements PropertyTypeProvider
{
    private const PROXY = 'Illuminate\\Support\\HigherOrderCollectionProxy';
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const COLLECTION = 'Illuminate\\Support\\Collection';
    private const ELOQUENT = 'Illuminate\\Database\\Eloquent\\Collection';

    public function __construct(
        private readonly EloquentPropertyProvider $properties,
    ) {}

    public function getTargets(): array
    {
        return [PropertyTarget::allProperties(self::PROXY), PropertyTarget::allProperties(self::MODEL)];
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $access = $context->access;
        $receiver = $access->receiverType;
        $proxy = $receiver->atomicTypes[0];
        if (
            $access->kind !== PropertyAccessKind::Read
            || count($receiver->atomicTypes) !== 1
            || ! $proxy instanceof NamedObjectType
            || $proxy->name !== self::PROXY
            || count($proxy->parameters ?? []) !== 3
            || ($proxy->parameters[0] ?? null)?->getLiteralString() !== 'map'
            || ($proxy->intersections ?? []) !== []
        ) {
            return null;
        }
        $value = $proxy->parameters[1] ?? null;
        $collection = $proxy->parameters[2] ?? null;
        $container = $collection?->atomicTypes[0] ?? null;
        if (
            $value === null
            || $collection === null
            || count($collection->atomicTypes) !== 1
            || ! $container instanceof NamedObjectType
            || ! in_array($container->name, [self::COLLECTION, self::ELOQUENT], true)
            || count($container->parameters ?? []) !== 2
            || ($container->intersections ?? []) !== []
        ) {
            return null;
        }
        $key = $container->parameters[0] ?? null;
        $element = $container->parameters[1] ?? null;
        if (
            $key === null
            || $element === null
            || ! $context->types->equals($element, $value)
            || ! $context->types->isContainedBy($key, Type::union(Type::int(), Type::string()))
        ) {
            return null;
        }
        $codebase = $context->codebase;
        $name = '$'.$access->property;
        if (
            $codebase->getDeclaringProperty(self::PROXY, $name) !== null
            || $codebase->getDeclaringMagicProperty(self::PROXY, $name) !== null
            || $codebase->getMethod($container->name, 'map') === null
        ) {
            return null;
        }
        $result = (new CollectionItemProperty($this->properties))->resolve(
            $value,
            $access->property,
            $context,
            $access->span,
        );
        if ($result === null) {
            return null;
        }
        $resultClass = $container->name === self::ELOQUENT
        && $context->types->isContainedBy($result, Type::namedObject(self::MODEL))
            ? self::ELOQUENT
            : self::COLLECTION;

        return new PropertyType(Type::namedObject($resultClass, $key, $result));
    }
}
