<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\VariableType;

/** Recognizes Laravel's broad generic container contracts without accepting concrete overrides. */
final class ContainerNativeContract
{
    public static function helper(FunctionLikeMetadata $function, string $name): bool
    {
        if ($function->declaredReturnType !== null) {
            return false;
        }
        $otherwise = self::genericConditional($function->returnType?->type, $name === 'app' ? 'abstract' : 'name');
        if ($otherwise === null) {
            return false;
        }
        if ($name === 'resolve') {
            return self::mixed($otherwise);
        }
        $nested = self::conditional($otherwise, 'abstract');
        if ($nested === null || ! self::null($nested->target) || ! self::mixed($nested->otherwise)) {
            return false;
        }
        $root = $nested->then->atomicTypes[0];

        return (
            count($nested->then->atomicTypes) === 1
            && $root instanceof NamedObjectType
            && in_array(
                strtolower($root->name),
                ['illuminate\\foundation\\application', 'illuminate\\container\\container'],
                true,
            )
        );
    }

    public static function make(FunctionLikeMetadata $method): bool
    {
        if ($method->declaredReturnType !== null) {
            return false;
        }
        $otherwise = self::genericConditional($method->returnType?->type, 'abstract');

        return $otherwise !== null && self::mixed($otherwise);
    }

    private static function genericConditional(?Type $type, string $variable): ?Type
    {
        $conditional = self::conditional($type, $variable);
        if ($conditional === null) {
            return null;
        }
        $target = $conditional->target->atomicTypes[0];
        $then = $conditional->then->atomicTypes[0];
        if (
            count($conditional->target->atomicTypes) !== 1
            || ! $target instanceof ScalarType
            || ! $target->refinement instanceof ClassLikeStringType
            || $target->refinement->parameterName === null
            || count($conditional->then->atomicTypes) !== 1
            || ! $then instanceof GenericParameterType
            || strcasecmp($then->name, $target->refinement->parameterName) !== 0
        ) {
            return null;
        }

        return $conditional->otherwise;
    }

    private static function conditional(?Type $type, string $variable): ?ConditionalType
    {
        $atom = $type?->atomicTypes[0] ?? null;
        $subject = $atom instanceof ConditionalType ? $atom->subject->atomicTypes[0] : null;

        return count($type?->atomicTypes ?? []) === 1
        && $atom instanceof ConditionalType
        && ! $atom->negated
        && count($atom->subject->atomicTypes) === 1
        && $subject instanceof VariableType
        && ltrim($subject->name, '$') === $variable
            ? $atom
            : null;
    }

    private static function mixed(Type $type): bool
    {
        return count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof MixedType;
    }

    private static function null(Type $type): bool
    {
        $atom = $type->atomicTypes[0];

        return (
            count($type->atomicTypes) === 1
            && $atom instanceof SimpleAtomicType
            && $atom->kind === SimpleAtomicTypeKind::Null
        );
    }
}
