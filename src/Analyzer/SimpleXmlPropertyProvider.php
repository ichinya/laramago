<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/** SimpleXML child reads can be an element proxy or null in a lookup chain. */
final class SimpleXmlPropertyProvider implements PropertyTypeProvider
{
    public function getTargets(): array
    {
        return [PropertyTarget::allProperties(\SimpleXMLElement::class)];
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        if ($context->access->kind !== PropertyAccessKind::Read) {
            return null;
        }

        $class = $context->access->class;
        $property = '$'.$context->access->property;
        $codebase = $context->codebase;
        $metadata = $codebase->getClass($class);
        if ($class !== \SimpleXMLElement::class
            || $metadata === null || $metadata->hasIncompleteHierarchy()
            || $codebase->getDeclaringProperty($class, $property) !== null
            || $codebase->getDeclaringMagicProperty($class, $property) !== null
            || $codebase->getMagicProperty($class, $property) !== null) {
            return null;
        }

        // An absent first-level child has an empty proxy, but looking up a
        // further child on that proxy can return null. The SDK has no stable
        // provenance for a receiver that separates those cases.
        return new PropertyType(readType: Type::union(Type::namedObject($class), Type::null()));
    }
}
