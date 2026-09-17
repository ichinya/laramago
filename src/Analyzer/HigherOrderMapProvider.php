<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Preserve the collection around a declared model method invoked through map. */
final class HigherOrderMapProvider implements MethodReturnTypeProvider
{
    private const PROXY = 'Illuminate\\Support\\HigherOrderCollectionProxy';
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const COLLECTION = 'Illuminate\\Support\\Collection';
    private const ELOQUENT = 'Illuminate\\Database\\Eloquent\\Collection';

    public function getTargets(): array
    {
        // Mago targets the declaring model or trait after @mixin resolution,
        // while retaining the original proxy receiver. Do not target every PHP call.
        return [
            MethodTarget::allMethods(self::MODEL),
            MethodTarget::allMethods('Illuminate\\Database\\Eloquent\\Concerns\\*'),
            MethodTarget::allMethods(self::PROXY),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $proxy = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
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
        $model = $value?->atomicTypes[0] ?? null;
        $container = $collection?->atomicTypes[0] ?? null;
        if (
            $value === null
            || count($value->atomicTypes) !== 1
            || ! $model instanceof NamedObjectType
            || ($model->parameters ?? []) !== []
            || ($model->intersections ?? []) !== []
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
            || ! $context->types->isContainedBy($value, Type::namedObject(self::MODEL))
            || ! $context->types->equals($element, $value)
            || ! $context->types->isContainedBy($key, Type::union(Type::int(), Type::string()))
        ) {
            return null;
        }
        $codebase = $context->codebase;
        foreach ($codebase->getMultipleClasses([
            self::PROXY,
            $model->name,
            ...$codebase->getClassAncestors($model->name),
        ]) as $class) {
            foreach ([...($class->pseudoMethods ?? []), ...($class->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, $call->name) === 0) {
                    return null;
                }
            }
        }
        if (
            $codebase->getMethod(self::PROXY, $call->name) !== null
            || $codebase->getDeclaringMethod(self::PROXY, $call->name) !== null
            || $codebase->getMethod($container->name, 'map') === null
        ) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->placeholder) {
                return null;
            }
        }
        $method = $codebase->getMethod($model->name, $call->name) ?? $codebase->getDeclaringMethod(
            $model->name,
            $call->name,
        );
        if (
            $method === null
            || $method->visibility !== Visibility::Public
            || $method->static
            || $method->templates !== []
            || ($codebase->getClassLike($method->identifier->class ?? $model->name)->templates ?? []) !== []
        ) {
            return null;
        }
        $return = (new HigherOrderMapResult)->resolve($method, $value, $context);
        if ($return === null) {
            return null;
        }
        // Eloquent switches to a base collection if any mapped value is not a model.
        // The base type also includes empty Eloquent collections and mixed branches.
        $resultClass = $container->name === self::ELOQUENT
        && $context->types->isContainedBy($return, Type::namedObject(self::MODEL))
            ? self::ELOQUENT
            : self::COLLECTION;

        return Type::namedObject($resultClass, $key, $return);
    }
}
