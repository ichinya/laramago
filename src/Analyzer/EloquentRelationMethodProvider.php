<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RelationMethodInference;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Infer simple relationship method generics from syntax, without calling models. */
final class EloquentRelationMethodProvider implements MethodReturnTypeProvider, InitializationHook
{
    private ?PhpSource $source = null;

    /** @var array<string, Type|null> */
    private array $returns = [];

    /** @var array<string, bool> */
    private array $models = [];

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->returns = [];
        $this->models = [];
    }

    public function getTargets(): array
    {
        // SDK targets match the declaring trait for imported methods. Receiver metadata
        // below and RelationMethodInference still require an Eloquent model.
        return [MethodTarget::allMethods('*')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $owner = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $owner instanceof NamedObjectType
            || ($owner->intersections ?? []) !== []
        ) {
            return null;
        }

        $class = strtolower($owner->name);
        $key = $class.'::'.strtolower($call->name);
        if (array_key_exists($key, $this->returns)) {
            return $this->returns[$key];
        }
        $this->models[$class] ??= in_array(
            'illuminate\\database\\eloquent\\model',
            array_map(strtolower(...), $context->codebase->getClassAncestors($owner->name)),
            true,
        );
        if (! $this->models[$class]) {
            return $this->returns[$key] = null;
        }

        return $this->returns[$key] = (new RelationMethodInference(
            $context->codebase,
            $this->source ??= new PhpSource($this->root),
        ))->infer($owner->name, $call->name);
    }
}
