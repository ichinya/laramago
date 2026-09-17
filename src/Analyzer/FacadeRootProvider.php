<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;

/** Resolves class-string facade accessors without loading the facade or application. */
final class FacadeRootProvider implements MethodReturnTypeProvider
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';

    private readonly PhpSource $source;

    public function __construct(string $projectRoot = '.')
    {
        $this->source = new PhpSource($projectRoot);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FACADE, 'getFacadeRoot')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $receiver = $context->invocation->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if ($receiver === null || count($receiver->atomicTypes) !== 1 || ! $object instanceof NamedObjectType) {
            return null;
        }
        foreach (['getFacadeRoot', 'resolveFacadeInstance'] as $name) {
            $method = $context->codebase->getDeclaringMethod($object->name, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::FACADE) !== 0) {
                return null;
            }
        }
        foreach ($context->codebase->getMultipleClasses([
            $object->name,
            ...$context->codebase->getClassAncestors($object->name),
        ]) as $class) {
            foreach ([...($class->pseudoMethods ?? []), ...($class->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, 'getFacadeRoot') === 0) {
                    return null;
                }
            }
        }
        $accessor = $context->codebase->getDeclaringMethod($object->name, 'getFacadeAccessor');
        if ($accessor === null) {
            return null;
        }
        $expression = (new ModelReflection($context->codebase, $this->source))->returnExpression($accessor);
        if (! $expression instanceof Node\Expr\ClassConstFetch) {
            return null;
        }
        $name = PhpSource::value($expression, $accessor->identifier->class, $object->name);
        if (
            ! is_string($name)
            || ($context->codebase->getClass($name) ?? $context->codebase->getInterface($name)) === null
        ) {
            return null;
        }

        // Laravel returns null when no facade application has been configured.
        return Type::union(Type::namedObject($name), Type::null());
    }
}
