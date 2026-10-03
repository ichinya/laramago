<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** A child of a proven actual native XML element is an object, even when empty. */
final class SimpleXmlProvenancePropertyProvider implements PropertyTypeProvider
{
    public function __construct(public readonly SimpleXmlProvenance $provenance = new SimpleXmlProvenance) {}

    public function getTargets(): array
    {
        return [PropertyTarget::allProperties(\SimpleXMLElement::class)];
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $access = $context->access;
        $receiver = $access->receiverType->atomicTypes[0] ?? null;
        $candidate = $this->provenance->property($access->span);
        if ($access->kind !== PropertyAccessKind::Read || $access->class !== \SimpleXMLElement::class
            || count($access->receiverType->atomicTypes) !== 1 || ! $receiver instanceof NamedObjectType
            || $receiver->name !== \SimpleXMLElement::class || ($receiver->parameters ?? []) !== []
            || ($receiver->intersections ?? []) !== [] || $receiver->static || $receiver->isThis || $candidate === null
            || $candidate['property']->name->name !== $access->property
            || ! SimpleXmlProvenance::current($candidate['proof']) || ! SimpleXmlProvenance::valid($context->codebase, $candidate['proof'])) {
            return null;
        }
        return new PropertyType(readType: Type::namedObject(\SimpleXMLElement::class));
    }
}
