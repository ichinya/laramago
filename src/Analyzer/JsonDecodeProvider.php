<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/**
 * Narrows json_decode(..., true) to the assoc=true JSON value space:
 * list<mixed>|array<string,mixed>|bool|int|float|string|null.
 *
 * Every other argument shape (assoc absent, false, null, dynamic or a truthy
 * int literal) keeps the native mixed analysis.
 */
final class JsonDecodeProvider implements FunctionReturnTypeProvider
{
    public function getTargets(): array
    {
        return [FunctionTarget::exact('json_decode')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $assoc = $call->getArgument(1, 'assoc');
        if (
            $call->kind !== InvocationKind::Function
            || $assoc === null
            || $assoc->unpacked
            || $assoc->placeholder
            || $assoc->type?->getLiteralBool() !== true
        ) {
            return null;
        }

        // Depth and flags never change this value space: json_decode never
        // returns false, so JSON_THROW_ON_ERROR only turns failures into
        // exceptions, and null stays reachable through the input "null".
        return Type::union(
            Type::list(Type::mixed()),
            Type::array(Type::string(), Type::mixed()),
            Type::bool(),
            Type::int(),
            Type::float(),
            Type::string(),
            Type::null(),
        );
    }
}
