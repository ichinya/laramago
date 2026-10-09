<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{InvocationKind, MethodReturnTypeProvider, MethodTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Literal structural XPath selectors contain DOMNode values; other expressions defer. */
final class DomXPathResultProvider implements MethodReturnTypeProvider
{
    public function getTargets(): array { return [MethodTarget::exact('DOMXPath', 'query')]; }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $expression = $call->getArgument(0, 'expression')?->type?->getLiteralString();
        // Namespace axes produce DOMNameSpaceNode, which is not a DOMNode in PHP.
        // Callable expressions can invoke registered PHP functions; do not certify them.
        if ($expression === null || str_contains($expression, '(')
            || preg_match('/namespace\s*::/', $expression) === 1) { return null; }
        $object = $call->receiverType?->atomicTypes[0] ?? null;
        if ($call->kind !== InvocationKind::InstanceMethod || ! $object instanceof NamedObjectType
            || count($call->receiverType->atomicTypes) !== 1) { return null; }
        $method = $context->codebase->getDeclaringMethod($object->name, 'query');
        $list = $context->codebase->getClassLike('DOMNodeList');
        if ($method === null || strcasecmp($method->identifier->class ?? '', 'DOMXPath') !== 0
            || ! $method->flags->contains(MetadataFlags::BUILTIN) || $method->flags->contains(MetadataFlags::USER_DEFINED)
            || $list === null || ! $list->flags->contains(MetadataFlags::BUILTIN) || count($list->templates) !== 1) { return null; }
        return Type::union(Type::namedObject('DOMNodeList', Type::namedObject('DOMNode')), Type::false());
    }
}
