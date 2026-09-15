<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use PhpParser\Node;

/** Refines actual factory count state while retaining its concrete class and native signatures. */
final class EloquentFactoryProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';
    private const PRESERVE_COUNT = [
        'state',
        'prependstate',
        'set',
        'sequence',
        'crossjoinsequence',
        'has',
        'hasattached',
        'for',
        'recycle',
        'aftermaking',
        'aftercreating',
        'withoutaftermaking',
        'withoutaftercreating',
        'withoutparents',
        'connection',
        'configure',
    ];

    private ?PhpSource $source = null;
    /** @var array<string, bool> */
    private array $standardFactories = [];

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->standardFactories = [];
    }

    public function getTargets(): array
    {
        // Trait methods are resolved on HasFactory rather than its consuming Model.
        return [MethodTarget::anyClass('factory'), MethodTarget::allMethods(FactoryReflection::FACTORY)];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
        ) {
            return null;
        }
        $atom = $receiver->atomicTypes[0];
        $source = $this->source ??= new PhpSource($this->root);
        $reflection = new FactoryReflection($context->codebase, $source);
        $name = strtolower($call->name);
        if ($name === 'factory' && $reflection->inherits($atom->name, ModelReflection::MODEL)) {
            $factory = $reflection->modelFactory($atom->name);
            if ($factory === null || ! $this->standardCore($reflection, $factory)) {
                return null;
            }
            $model = $reflection->factoryModel($factory);
            if ($model === null) {
                return null;
            }
            $count = $call->getArgument(0, 'count');
            $type = $count === null && $call->arguments === [] ? Type::null() : $count?->type;
            // With only a named state argument, HasFactory still defaults count to null.
            if (
                $count === null
                && count(array_filter($call->arguments, static fn ($arg): bool => $arg->name !== 'state')) === 0
            ) {
                $type = Type::null();
            }
            foreach ($call->arguments as $argument) {
                if ($argument->unpacked || $argument->placeholder) {
                    $type = null;
                    break;
                }
            }

            $factoryType = Type::namedObject($factory)->atomicTypes[0];

            return $factoryType instanceof NamedObjectType
                ? $this->refine($factoryType, $model, $this->countState($type, true))
                : null;
        }
        if (
            ! $reflection->inherits($atom->name, FactoryReflection::FACTORY)
            || ! $this->standardCore($reflection, $atom->name)
        ) {
            return null;
        }
        $method = $reflection->method($atom->name, $name);
        if ($method === null) {
            return null;
        }
        $model = $this->model($atom) ?? $reflection->factoryModel($atom->name);
        if ($model === null) {
            return null;
        }
        $count = $this->count($atom);
        if (strcasecmp($method->identifier->class ?? '', FactoryReflection::FACTORY) !== 0) {
            $return = $method->returnType?->type ?? $method->declaredReturnType?->type;
            if (
                $return === null
                || count($return->atomicTypes) !== 1
                || ! $return->atomicTypes[0] instanceof NamedObjectType
                || ! $return->atomicTypes[0]->static
                && ! $return->atomicTypes[0]->isThis
            ) {
                return null;
            }
            $node = (new ModelReflection($context->codebase, $source))->methodNode($method);
            $count = $this->customCount($node, $count, $reflection, $atom->name);

            return $this->refine($atom, $model, $count);
        }
        if ($name === 'new' || $name === 'times') {
            $configuration = $reflection->method($atom->name, 'configure');
            $count =
                $configuration === null
                || strcasecmp($configuration->identifier->class ?? '', FactoryReflection::FACTORY) === 0
                    ? Type::null()
                    : null;
            if ($name === 'times') {
                $count = $this->countState($call->getArgument(0, 'count')?->type);
            }

            return $this->refine($atom, $model, $count);
        }
        if ($name === 'count') {
            return $this->refine($atom, $model, $this->countState($call->getArgument(0, 'count')?->type));
        }
        if ($name === 'foreachsequence') {
            return $this->refine($atom, $model, Type::int());
        }
        if (in_array($name, self::PRESERVE_COUNT, true)) {
            return $this->refine($atom, $model, $count);
        }
        $single = Type::namedObject($model);
        $many = Type::namedObject(self::COLLECTION, Type::int(), $single);
        if (in_array($name, ['createone', 'createonequietly', 'makeone', 'newmodel'], true)) {
            return $single;
        }
        if (in_array($name, ['createmany', 'createmanyquietly', 'makemany'], true)) {
            return $many;
        }
        if (in_array($name, ['create', 'createquietly', 'make'], true)) {
            return $count === null ? Type::union($single, $many) : ((string) $count === 'null' ? $single : $many);
        }

        return null;
    }

    private function standardCore(FactoryReflection $reflection, string $factory): bool
    {
        if (array_key_exists($factory, $this->standardFactories)) {
            return $this->standardFactories[$factory];
        }
        foreach ([
            '__construct',
            'new',
            'newInstance',
            'newModel',
            'modelName',
            'create',
            'make',
            'makeInstance',
            'count',
            'state',
        ] as $name) {
            $method = $reflection->method($factory, $name);
            if ($method !== null && strcasecmp($method->identifier->class ?? '', FactoryReflection::FACTORY) !== 0) {
                return $this->standardFactories[$factory] = false;
            }
        }

        // Publish only complete metadata; concurrent SDK requests can interleave in a worker.
        return $this->standardFactories[$factory] = ! $reflection->hasStateMutation($factory);
    }

    private function countState(?Type $type, bool $acceptState = false): ?Type
    {
        if ($type === null || count($type->atomicTypes) !== 1) {
            return null;
        }
        $atom = $type->atomicTypes[0];
        if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
            return Type::null();
        }
        if ($atom instanceof ScalarType && $atom->kind === ScalarTypeKind::Integer) {
            return Type::int();
        }
        if (
            $acceptState
            && ($atom instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType
            || $atom instanceof \Mago\Sdk\Analyzer\Type\ListType
            || $atom instanceof \Mago\Sdk\Analyzer\Type\CallableType)
        ) {
            return Type::null();
        }

        return null;
    }

    private function model(NamedObjectType $factory): ?string
    {
        foreach ([$factory, ...($factory->intersections ?? [])] as $atom) {
            if ($atom instanceof NamedObjectType && strcasecmp($atom->name, FactoryReflection::FACTORY) === 0) {
                $parameter = $atom->parameters[0] ?? null;
                $model =
                    $parameter !== null && count($parameter->atomicTypes) === 1 ? $parameter->atomicTypes[0] : null;
                if ($model instanceof NamedObjectType) {
                    return $model->name;
                }
            }
        }

        return null;
    }

    private function count(NamedObjectType $factory): ?Type
    {
        foreach ($factory->intersections ?? [] as $intersection) {
            if ($intersection instanceof ObjectShapeType) {
                foreach ($intersection->properties as $property) {
                    if ($property->name === 'count' && ! $property->optional) {
                        return $this->countState($property->type);
                    }
                }
            }
        }

        return null;
    }

    private function refine(NamedObjectType $factory, string $model, ?Type $count): Type
    {
        $intersections = [];
        foreach ($factory->intersections ?? [] as $intersection) {
            if ($intersection instanceof ObjectShapeType) {
                $properties = array_values(array_filter(
                    $intersection->properties,
                    static fn (ObjectProperty $property): bool => $property->name !== 'count',
                ));
                if ($properties !== []) {
                    $intersections[] = new ObjectShapeType($properties, $intersection->sealed);
                }
            } elseif (
                ! $intersection instanceof NamedObjectType
                || strcasecmp($intersection->name, FactoryReflection::FACTORY) !== 0
            ) {
                $intersections[] = $intersection;
            }
        }
        if ($count !== null) {
            $intersections[] = new ObjectShapeType([new ObjectProperty('count', false, $count)], false);
        }

        return Type::fromAtomic(
            new NamedObjectType(
                $factory->name,
                strcasecmp($factory->name, FactoryReflection::FACTORY) === 0
                    ? [Type::namedObject($model)]
                    : $factory->parameters,
                $factory->variances,
                $factory->static,
                $factory->isThis,
                $intersections === [] ? null : $intersections,
                $factory->remappedParameters,
            ),
        );
    }

    private function customCount(
        ?Node\Stmt\ClassMethod $node,
        ?Type $count,
        FactoryReflection $reflection,
        string $factory,
    ): ?Type {
        $statements = $node?->stmts ?? [];
        if (
            count($statements) !== 1
            || ! $statements[0] instanceof Node\Stmt\Return_
            || $statements[0]->expr === null
        ) {
            return null;
        }
        [$root, $calls] = PhpSource::chain($statements[0]->expr);
        if (! $root instanceof Node\Expr\Variable || $root->name !== 'this') {
            return null;
        }
        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            $method = $reflection->method($factory, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', FactoryReflection::FACTORY) !== 0) {
                return null;
            }
            if ($name === 'count') {
                $value = PhpSource::value(PhpSource::argument($call->args, 0, 'count'));
                $count = is_int($value) ? Type::int() : ($value === null ? Type::null() : null);
            } elseif ($name === 'foreachsequence') {
                $count = Type::int();
            } elseif (! in_array($name, self::PRESERVE_COUNT, true)) {
                return null;
            }
        }

        return $count;
    }
}
