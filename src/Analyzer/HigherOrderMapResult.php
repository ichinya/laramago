<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\VariableType;

/** Resolve concrete method contracts, including parameter-dependent result branches. */
final class HigherOrderMapResult
{
    public function resolve(FunctionLikeMetadata $method, Type $model, ReturnTypeProviderContext $context): ?Type
    {
        $return = $method->returnType->type ?? $method->declaredReturnType?->type;
        $declared = $method->declaredReturnType?->type;
        if ($return === null) {
            return null;
        }
        // A native concrete override must not inherit an incompatible parent PHPDoc.
        if ($declared !== null && ! $context->types->isContainedBy($return, $declared)) {
            $return = $declared;
        }

        return $this->result($return, $method, $model, $context);
    }

    private function result(
        Type $return,
        FunctionLikeMetadata $method,
        Type $model,
        ReturnTypeProviderContext $context,
    ): ?Type {
        $result = null;
        foreach ($return->atomicTypes as $atom) {
            $type = $this->atomicResult($atom, $method, $model, $context);
            if ($type === null) {
                return null;
            }
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }

    private function atomicResult(
        AtomicType $atom,
        FunctionLikeMetadata $method,
        Type $model,
        ReturnTypeProviderContext $context,
    ): ?Type {
        if ($atom instanceof ConditionalType) {
            $type = $this->conditional($atom, $method, $context);

            return $type === null ? null : $this->result($type, $method, $model, $context);
        }
        if ($atom instanceof NamedObjectType && ($atom->static || $atom->isThis)) {
            $modelAtom = $model->atomicTypes[0];

            return $modelAtom instanceof NamedObjectType
            && ($atom->intersections ?? []) === []
            && ($atom->parameters ?? []) === []
                ? Type::namedObject($modelAtom->name)
                : null;
        }
        if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Void) {
            // PHP's value for a completed void callback is null.
            return Type::null();
        }
        $type = Type::fromAtomic($atom);

        return self::unresolved($type) ? null : $type;
    }

    private function conditional(
        ConditionalType $type,
        FunctionLikeMetadata $method,
        ReturnTypeProviderContext $context,
    ): ?Type {
        $subject = $type->subject->atomicTypes[0];
        if (
            count($type->subject->atomicTypes) !== 1
            || ! $subject instanceof VariableType
            || self::unresolved($type->target)
        ) {
            return null;
        }
        $actual = null;
        foreach ($method->parameters as $position => $parameter) {
            if (ltrim($parameter->name, '$') !== ltrim($subject->name, '$')) {
                continue;
            }
            $argument = $context->invocation->getArgument($position, ltrim($parameter->name, '$'));
            $actual = $argument === null ? $parameter->defaultType?->type : $argument->type;
            break;
        }
        foreach ($context->invocation->arguments as $argument) {
            if (! $argument->unpacked) {
                continue;
            }
            $actual = null;
            break;
        }
        if ($actual === null) {
            return $this->both($type, $context);
        }
        if ($context->types->isContainedBy($actual, $type->target)) {
            return $type->negated ? $type->otherwise : $type->then;
        }
        if (! $context->types->canBeIdentical($actual, $type->target)) {
            return $type->negated ? $type->then : $type->otherwise;
        }

        return $this->both($type, $context);
    }

    private function both(ConditionalType $type, ReturnTypeProviderContext $context): Type
    {
        if ($context->types->isContainedBy($type->then, $type->otherwise)) {
            return $type->otherwise;
        }
        if ($context->types->isContainedBy($type->otherwise, $type->then)) {
            return $type->then;
        }

        return Type::union($type->then, $type->otherwise);
    }

    /** Never export unresolved templates or contextual types inside nested shapes. */
    private static function unresolved(mixed $value): bool
    {
        if (
            $value instanceof GenericParameterType
            || $value instanceof ConditionalType
            || $value instanceof AliasType
            || $value instanceof VariableType
        ) {
            return true;
        }
        if ($value instanceof NamedObjectType && ($value->static || $value->isThis)) {
            return true;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) && array_filter($value, self::unresolved(...)) !== [];
    }
}
