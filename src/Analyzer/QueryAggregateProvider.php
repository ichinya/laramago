<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeQueryAggregate;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

/** Laravel's SQL aggregate result contract, including calls forwarded by Eloquent. */
final class QueryAggregateProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';
    private const ELOQUENT = 'Illuminate\\Database\\Eloquent\\Builder';
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const RELATIONS = [
        'HasMany', 'HasOne', 'BelongsTo', 'BelongsToMany', 'HasManyThrough',
        'HasOneThrough', 'MorphMany', 'MorphOne', 'MorphToMany', 'MorphedByMany',
    ];
    private const METHODS = ['sum', 'avg', 'average'];

    private readonly EloquentModelDispatch $models;
    private NativeQueryAggregate $contract;

    public function __construct(
        private readonly string $projectRoot = '.',
    )
    {
        $this->models = new EloquentModelDispatch;
        $this->contract = new NativeQueryAggregate(new PhpSource($projectRoot));
    }

    public function initialize(InitializationContext $context): void
    {
        $this->contract = new NativeQueryAggregate(new PhpSource($this->projectRoot));
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ([self::QUERY, self::ELOQUENT, self::MODEL] as $class) {
            foreach (self::METHODS as $name) {
                $targets[] = MethodTarget::exact($class, $name);
            }
        }
        foreach (self::RELATIONS as $relation) {
            foreach (self::METHODS as $name) {
                $targets[] = MethodTarget::exact('Illuminate\\Database\\Eloquent\\Relations\\'.$relation, $name);
            }
        }

        return $targets;
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $name = strtolower($call->name);
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if (
            ! in_array($name, self::METHODS, true)
            || count($call->receiverType?->atomicTypes ?? []) !== 1
            || ! $receiver instanceof NamedObjectType
            || ! $this->validArguments($call)
            || ! $this->contract->proves($context->codebase, $name)
        ) {
            return null;
        }
        $class = $receiver->name;
        if ($this->inherits($context->codebase, $class, self::QUERY)) {
            if (
                ! $this->methodOwnedBy($context->codebase, $class, $name, self::QUERY)
                || ! $this->methodOwnedBy($context->codebase, $class, 'aggregate', self::QUERY)
            ) {
                return null;
            }
        } elseif ($this->inherits($context->codebase, $class, self::MODEL)) {
            if (
                $this->models->modelType($context->codebase, $call, self::QUERY) === null
                || ! $this->standardModel($context->codebase, $class, $name)
            ) {
                return null;
            }
        } elseif ($this->inherits($context->codebase, $class, self::ELOQUENT)) {
            if (! $this->standardBuilder($context->codebase, $class, $name)) {
                return null;
            }
        } elseif (! $this->standardRelation($context->codebase, $receiver, $name)) {
            return null;
        }

        $numeric = Type::union(self::numericString(), Type::int(), Type::float());

        return $name === 'sum' ? $numeric : Type::union($numeric, Type::null());
    }

    private function validArguments(Invocation $call): bool
    {
        if (count($call->arguments) !== 1) {
            return false;
        }
        $argument = $call->arguments[0];

        return ! $argument->placeholder
            && ! $argument->unpacked
            && ($argument->name === null || $argument->name === 'column');
    }

    private function standardModel(Codebase $codebase, string $class, string $name): bool
    {
        foreach (['newBaseQueryBuilder', 'hasNamedScope', 'callNamedScope', 'isScopeMethodWithAttribute'] as $method) {
            if ($this->models->overrides($codebase, $class, $method)) {
                return false;
            }
        }

        return $this->method($codebase, $class, $name) === null
            && $this->method($codebase, $class, 'scope'.ucfirst($name)) === null;
    }

    private function standardBuilder(Codebase $codebase, string $class, string $name): bool
    {
        foreach ([$name, '__call', 'getQuery', 'toBase'] as $method) {
            $metadata = $this->method($codebase, $class, $method);
            if (
                $metadata !== null
                && strcasecmp($metadata->identifier->class ?? '', self::ELOQUENT) !== 0
                && ! ($method === $name && strcasecmp($metadata->identifier->class ?? '', self::QUERY) === 0)
            ) {
                return false;
            }
        }
        if ($this->method($codebase, self::ELOQUENT, $name) !== null) {
            return false;
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            foreach ([...($metadata->pseudoMethods ?? []), ...($metadata->staticPseudoMethods ?? [])] as $pseudo) {
                if (strcasecmp($pseudo, $name) === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private function standardRelation(Codebase $codebase, NamedObjectType $receiver, string $name): bool
    {
        $class = $receiver->name;
        if (! in_array($class, array_map(
            static fn (string $relation): string => 'Illuminate\\Database\\Eloquent\\Relations\\'.$relation,
            self::RELATIONS,
        ), true)) {
            return false;
        }
        foreach ([$name, '__call', 'getQuery'] as $method) {
            $metadata = $this->method($codebase, $class, $method);
            if (
                $metadata !== null
                && ($method === $name
                    || strcasecmp($metadata->identifier->class ?? '', 'Illuminate\\Database\\Eloquent\\Relations\\Relation') !== 0)
            ) {
                return false;
            }
        }
        $model = $receiver->parameters[0] ?? null;
        $atom = $model?->atomicTypes[0] ?? null;
        if (
            $model === null
            || count($model->atomicTypes) !== 1
            || ! $atom instanceof NamedObjectType
            || ! $this->models->supportsModel($codebase, $atom->name, $name)
            || ! $this->standardModel($codebase, $atom->name, $name)
            || ! $this->standardBuilder($codebase, self::ELOQUENT, $name)
        ) {
            return false;
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            foreach ([...($metadata->pseudoMethods ?? []), ...($metadata->staticPseudoMethods ?? [])] as $pseudo) {
                if (strcasecmp($pseudo, $name) === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    private function methodOwnedBy(Codebase $codebase, string $class, string $name, string $owner): bool
    {
        $method = $this->method($codebase, $class, $name);

        return $method !== null && strcasecmp($method->identifier->class ?? '', $owner) === 0;
    }

    private function method(Codebase $codebase, string $class, string $name): ?FunctionLikeMetadata
    {
        return $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
    }

    private function inherits(Codebase $codebase, string $class, string $parent): bool
    {
        return strcasecmp($class, $parent) === 0
            || in_array(strtolower($parent), array_map(strtolower(...), $codebase->getClassAncestors($class)), true);
    }

    private static function numericString(): Type
    {
        return Type::fromAtomic(new ScalarType(ScalarTypeKind::String, new StringType(
            StringLiteralKind::General,
            null,
            true,
            false,
            true,
            false,
            StringCasing::Unspecified,
        )));
    }
}
