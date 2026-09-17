<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\AtomicType;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** A callable target whose runtime signature can be read from Mago metadata. */
final readonly class MacroCallable
{
    public function __construct(
        public string $class,
        public string $method,
        public bool $static,
    ) {}

    /** @return array{EffectiveCallableSignature, Type}|null */
    public function contract(Codebase $codebase): ?array
    {
        $method = $codebase->getMethod($this->class, $this->method) ?? $codebase->getDeclaringMethod(
            $this->class,
            $this->method,
        );
        $declaring = $method?->identifier->class;
        if (
            $method === null
            || $method->visibility !== Visibility::Public
            || $method->static !== $this->static
            || $method->abstract
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || $method->templates !== []
            || $declaring === null
            || ($codebase->getClassLike($declaring)?->templates ?? []) !== []
        ) {
            return null;
        }

        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $type = $parameter->type?->type ?? $parameter->declaredType?->type;
            $closureThis = $parameter->closureThisType?->type;
            if (
                $type === null
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || self::unresolved($type)
                || self::unresolved($closureThis)
            ) {
                return null;
            }
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $type,
                closureThisType: $closureThis,
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
            );
        }
        $return = $method->returnType?->type ?? $method->declaredReturnType?->type;
        if ($return === null || self::unresolved($return)) {
            return null;
        }

        return [
            new EffectiveCallableSignature($parameters, displayName: $declaring.'::'.$method->originalName),
            $return,
        ];
    }

    /** Never publish a contract that still needs call-site or class-template substitution. */
    private static function unresolved(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
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
        if ($value instanceof Type) {
            $value = $value->atomicTypes;
        } elseif ($value instanceof AtomicType || is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) && array_filter($value, self::unresolved(...)) !== [];
    }
}
