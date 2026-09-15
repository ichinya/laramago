<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Preserves the array returned by FormRequest when no input field is selected. */
final class ValidatedInputProvider implements MethodReturnTypeProvider
{
    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FORM_REQUEST, 'validated')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
            || ! $this->nativeMethod($context->codebase, $receiver->atomicTypes[0]->name)
        ) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $key = $call->getArgument(0, 'key');
        $keyType = $key?->type;
        // data_get returns the entire validated array for an omitted or null key.
        // Selecting a field says nothing about its value, even with a default.
        if ($key !== null && ($keyType === null || ! $context->types->isContainedBy($keyType, Type::null()))) {
            return null;
        }

        return Type::array(Type::union(Type::int(), Type::string()), Type::mixed());
    }

    private function nativeMethod(Codebase $codebase, string $class): bool
    {
        $method = $codebase->getMethod($class, 'validated') ?? $codebase->getDeclaringMethod($class, 'validated');
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::FORM_REQUEST) !== 0) {
            return false;
        }
        $returnType = $method->returnType->type ?? $method->declaredReturnType?->type;
        if ($returnType !== null && (string) $returnType !== 'mixed') {
            return false;
        }
        $validator = $codebase->getDeclaringProperty($class, '$validator') ?? $codebase->getProperty(
            $class,
            '$validator',
        );
        $native = $codebase->getProperty(self::FORM_REQUEST, '$validator');
        $location = $validator->nameLocation ?? $validator?->location;
        $nativeLocation = $native->nameLocation ?? $native?->location;
        if (
            $location === null
            || $nativeLocation === null
            || $location->file !== $nativeLocation->file
            || $location->span->start !== $nativeLocation->span->start
        ) {
            return false;
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            foreach ([...($metadata->pseudoMethods ?? []), ...($metadata->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, 'validated') === 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
