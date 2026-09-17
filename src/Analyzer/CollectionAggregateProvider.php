<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\StringType;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** In-memory aggregate contracts; SQL builders are intentionally outside this provider. */
final class CollectionAggregateProvider implements MethodReturnTypeProvider
{
    private const COLLECTION = 'Illuminate\\Support\\Collection';
    private const ELOQUENT = 'Illuminate\\Database\\Eloquent\\Collection';
    private const ENUMERATES = 'Illuminate\\Support\\Traits\\EnumeratesValues';

    public function __construct(
        private readonly EloquentPropertyProvider $properties,
    ) {}

    public function getTargets(): array
    {
        $targets = [];
        foreach ([self::COLLECTION, self::ELOQUENT, self::ENUMERATES] as $class) {
            foreach (['min', 'max', 'sum'] as $method) {
                $targets[] = MethodTarget::exact($class, $method);
            }
        }

        return $targets;
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $atom = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $atom instanceof NamedObjectType
            || ! in_array($atom->name, [self::COLLECTION, self::ELOQUENT], true)
            || count($atom->parameters ?? []) !== 2
            || ($atom->intersections ?? []) !== []
        ) {
            return null;
        }
        $method = $context->codebase->getMethod($atom->name, $call->name) ?? $context->codebase->getDeclaringMethod(
            $atom->name,
            $call->name,
        );
        $native = $method?->returnType->type ?? $method?->declaredReturnType?->type;
        $declared = $method?->declaredReturnType?->type;
        $propertyCall = count($call->arguments) === 1 && $call->arguments[0]->type?->getLiteralString() !== null;
        // Refine Laravel's mixed contract, never a concrete native or PHPDoc override.
        if (
            $method === null
            || $method->static
            || $method->visibility !== Visibility::Public
            || ! in_array($method->identifier->class, [self::COLLECTION, self::ELOQUENT, self::ENUMERATES], true)
            || $declared !== null
            && ! $this->broadContract($declared, $propertyCall)
            || $native !== null
            && ! $this->broadContract($native, $propertyCall)
        ) {
            return null;
        }
        $items = $atom->parameters[1] ?? null;
        if ($items === null || ! CollectionItemProperty::concrete($items) || count($call->arguments) > 1) {
            return null;
        }
        $values = $items;
        foreach ($call->arguments as $argument) {
            if (
                $argument->unpacked
                || $argument->placeholder
                || $argument->name !== null
                && $argument->name !== 'callback'
            ) {
                return null;
            }
            $type = $argument->type;
            if ($type === null) {
                return null;
            }
            if (
                count($type->atomicTypes) === 1
                && $type->atomicTypes[0] instanceof SimpleAtomicType
                && $type->atomicTypes[0]->kind === SimpleAtomicTypeKind::Null
            ) {
                continue;
            }
            $name = $type->getLiteralString();
            if ($name === null) {
                return null;
            }
            $values = (new CollectionItemProperty($this->properties))->resolve(
                $items,
                $name,
                $context,
                $call->span,
                true,
            );
            if ($values === null) {
                return null;
            }
        }
        if (strtolower($call->name) !== 'sum') {
            return Type::union($values, Type::null());
        }
        $numeric = false;
        foreach ($values->atomicTypes as $value) {
            if (
                $value instanceof SimpleAtomicType
                && in_array($value->kind, [SimpleAtomicTypeKind::Null, SimpleAtomicTypeKind::Never], true)
            ) {
                continue;
            }
            if (! $value instanceof ScalarType) {
                return null;
            }
            if (! in_array(
                $value->kind,
                [ScalarTypeKind::Integer, ScalarTypeKind::Float, ScalarTypeKind::Boolean],
                true,
            )) {
                if (
                    $value->kind !== ScalarTypeKind::String
                    || ! $value->refinement instanceof StringType
                    || ! $value->refinement->numeric
                    && ! ($value->refinement->literalValue !== null
                    && is_numeric($value->refinement->literalValue))
                ) {
                    return null;
                }
            }
            $numeric = true;
        }

        // Zero is the empty seed, and arbitrarily many integers can overflow to float.
        return $numeric ? Type::union(Type::int(), Type::float()) : Type::literalInt(0);
    }

    private function broadContract(Type $type, bool $propertyCall, int $depth = 0): bool
    {
        if (count($type->atomicTypes) !== 1) {
            return false;
        }
        $atom = $type->atomicTypes[0];
        if ($atom instanceof MixedType) {
            return true;
        }
        // Current Laravel uses a conditional whose literal-property branch is mixed.
        if (! $atom instanceof ConditionalType || $atom->negated || $depth > 1) {
            return false;
        }
        $subject = $atom->subject->atomicTypes[0];
        $target = $atom->target->atomicTypes[0];

        return (
            count($atom->subject->atomicTypes) === 1
            && $subject instanceof VariableType
            && ltrim($subject->name, '$') === 'callback'
            && count($atom->target->atomicTypes) === 1
            && (
                $target instanceof CallableType
                || $target instanceof SimpleAtomicType
                && $target->kind === SimpleAtomicTypeKind::Null
            )
            && $this->broadContract(
                $target instanceof SimpleAtomicType && ! $propertyCall ? $atom->then : $atom->otherwise,
                $propertyCall,
                $depth + 1,
            )
        );
    }
}
