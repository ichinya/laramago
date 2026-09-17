<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\SchemaIndex;
use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\PropertyAccess;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use PhpParser\Node;
use PhpParser\ParserFactory;

/** Terminal projections on fresh, standard model queries, without mutable builder state. */
final class EloquentProjectionProvider implements
    MethodReturnTypeProvider,
    CallableSignatureProvider,
    InitializationHook
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const COLLECTION = 'Illuminate\\Support\\Collection';

    private ?PhpSource $source = null;
    private ?SchemaIndex $schema = null;

    public function __construct(
        private readonly string $root,
        private readonly EloquentPropertyProvider $properties,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->schema = null;
    }

    public function getTargets(): array
    {
        return array_map(static fn (string $name): MethodTarget => MethodTarget::exact(ModelReflection::MODEL, $name), [
            'first',
            'firstOrFail',
            'sole',
            'get',
            'value',
            'pluck',
        ]);
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        if (! in_array(strtolower($context->invocation->name), ['value', 'pluck'], true)) {
            return (new EloquentQueryProvider)->getCallableSignature($context);
        }
        $dispatch = new EloquentModelDispatch;

        return $dispatch->modelType($context->codebase, $context->invocation) === null
            ? null
            : $dispatch->signature($context->codebase, $context->invocation->name);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $result = $this->resolve($context);
        if ($result !== null || ! in_array(strtolower($context->invocation->name), ['value', 'pluck'], true)) {
            return $result;
        }
        if ((new EloquentModelDispatch)->modelType($context->codebase, $context->invocation) === null) {
            return null;
        }
        $method = $context->codebase->getMethod(
            self::BUILDER,
            $context->invocation->name,
        ) ?? $context->codebase->getDeclaringMethod(self::BUILDER, $context->invocation->name);
        $installed = $method?->returnType?->type ?? $method?->declaredReturnType?->type;
        $native = $method?->declaredReturnType?->type;
        if ($installed !== null && $native !== null && ! $context->types->isContainedBy($installed, $native)) {
            $installed = $native;
        }

        return $installed !== null && $this->concreteReturn($installed) ? $installed : null;
    }

    private function resolve(ReturnTypeProviderContext $context): ?Type
    {
        $dispatch = new EloquentModelDispatch;
        $call = $context->invocation;
        $model = $dispatch->modelType($context->codebase, $call);
        $atom = $model?->atomicTypes[0] ?? null;
        if ($model === null || count($model->atomicTypes) !== 1 || ! $atom instanceof NamedObjectType) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $source = $this->source ??= new PhpSource($this->root);
        $schemaIndex = $this->schema;
        if ($schemaIndex === null) {
            $schemaIndex = new SchemaIndex($source);
            $schemaIndex->load();
            $this->schema = $schemaIndex;
        }
        $reflection = new ModelReflection($context->codebase, $source);
        foreach ([
            'newInstance',
            'newFromBuilder',
            'setRawAttributes',
            '__get',
            '__set',
            'getAttribute',
            'setAttribute',
            'getAttributeValue',
            'castAttribute',
            'getDates',
            'hasCast',
            'hasAnyGetMutator',
        ] as $name) {
            if ($reflection->customMethod($atom->name, $name) !== null) {
                return null;
            }
        }
        $table = $reflection->table($atom->name);
        if ($table === null) {
            return null;
        }
        $name = strtolower($call->name);
        if ($name === 'value' || $name === 'pluck') {
            $column = $this->physicalColumn($table, $call->getArgument(0, 'column')?->type?->getLiteralString());
            if ($column === null) {
                return null;
            }
            // Accessors without a physical column are not selectable SQL fields.
            if ($schemaIndex->column($table, $column) === null) {
                return null;
            }
            $type = $this->properties->getPropertyType(
                new PropertyTypeProviderContext(
                    $context->phpVersion,
                    $context->codebase,
                    new PropertyAccess($atom->name, $column, PropertyAccessKind::Read, $model, $call->span),
                    $context->types,
                    $context->cancellation,
                ),
            )?->readType;
            if ($type === null || ! ExplicitGenericType::concrete($type)) {
                return null;
            }
            $keyType = Type::int();
            if ($name === 'value') {
                $result = Type::union($type, Type::null());
            } else {
                $key = $call->getArgument(1, 'key');
                if ($key !== null && ($key->type === null || ! $context->types->equals($key->type, Type::null()))) {
                    $keyColumn = $this->physicalColumn($table, $key->type?->getLiteralString());
                    $keySchema = $keyColumn === null ? null : $schemaIndex->column($table, $keyColumn);
                    if ($keySchema === null) {
                        return null;
                    }
                    // Query Builder indexes raw database values before Eloquent casts values.
                    // PHP converts integer strings, floats and booleans to integer keys;
                    // null becomes an empty-string key. String columns may contain integers.
                    $keyType = in_array($keySchema->type, ['int', 'float', 'bool'], true)
                        ? Type::int()
                        : Type::union(Type::int(), Type::string());
                    if ($keySchema->nullable) {
                        $keyType = Type::union($keyType, Type::string());
                    }
                }
                $result = Type::namedObject(self::COLLECTION, $keyType, $type);
            }
            $method = $context->codebase->getMethod(self::BUILDER, $name) ?? $context->codebase->getDeclaringMethod(
                self::BUILDER,
                $name,
            );
            $installed = $method?->returnType?->type ?? $method?->declaredReturnType?->type;
            $native = $method?->declaredReturnType?->type;
            if ($native !== null && ! $context->types->isContainedBy($result, $native)) {
                return null;
            }

            if ($name === 'pluck') {
                $collection = $installed?->atomicTypes[0] ?? null;
                if (
                    $installed === null
                    || count($installed->atomicTypes) !== 1
                    || ! $collection instanceof NamedObjectType
                    || $collection->name !== self::COLLECTION
                    || $collection->parameters === null
                    || count($collection->parameters) !== 2
                    || ! $context->types->isContainedBy($keyType, $collection->parameters[0])
                    || ! $context->types->isContainedBy($type, $collection->parameters[1])
                ) {
                    return null;
                }

                return $result;
            }

            return $installed !== null && $context->types->isContainedBy($result, $installed) ? $result : null;
        }

        $argument = $call->getArgument(0, 'columns');
        if ($argument === null || ! $this->standardTerminalContract($context, $name)) {
            return null;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse('<?php '.$argument->expression.';');
        } catch (\PhpParser\Error) {
            return null;
        }
        $expression = $nodes[0] ?? null;
        $columns = $expression instanceof Node\Stmt\Expression ? PhpSource::value($expression->expr) : null;
        $columns = is_string($columns) ? [$columns] : $columns;
        $casts = $reflection->casts($atom->name);
        if (! is_array($columns) || ! is_array($casts)) {
            return null;
        }
        $properties = [];
        foreach ($columns as $column) {
            if (! is_string($column)) {
                return null;
            }
            if ($column === '*' || $column === $table.'.*') {
                continue;
            }
            if ($this->physicalColumn($table, $column) !== null) {
                continue;
            }
            $matches = [];
            if (! preg_match(
                '/^([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?) +as +([a-zA-Z_][a-zA-Z0-9_]*)$/iD',
                $column,
                $matches,
            )) {
                return null;
            }
            [, $field, $alias] = $matches;
            $field = $this->physicalColumn($table, $field);
            if ($field === null) {
                return null;
            }
            $schema = $schemaIndex->column($table, $field);
            $studly = ModelReflection::studly($alias);
            if (
                $schema === null
                || $schemaIndex->column($table, $alias) !== null
                || array_key_exists($alias, $casts)
                || isset($properties[$alias])
                || $reflection->automaticDate($atom->name, $alias)
                || $reflection->method($atom->name, 'get'.$studly.'Attribute') !== null
                || $reflection->method($atom->name, 'set'.$studly.'Attribute') !== null
                || $reflection->method($atom->name, lcfirst($studly)) !== null
                || $context->codebase->getDeclaringProperty($atom->name, '$'.$alias) !== null
                || $context->codebase->getDeclaringMagicProperty($atom->name, '$'.$alias) !== null
            ) {
                return null;
            }
            // SQL aliases hydrate raw column values, not the source attribute's cast.
            $type = AttributeTypes::column($schema)->readType;
            if ($type === null) {
                return null;
            }
            $properties[$alias] = new ObjectProperty($alias, false, $type);
        }
        if ($properties === []) {
            return null;
        }
        $projected = Type::fromAtomic(
            new NamedObjectType(
                $atom->name,
                $atom->parameters,
                $atom->variances,
                $atom->static,
                false,
                [new ObjectShapeType(array_values($properties), false)],
                $atom->remappedParameters,
            ),
        );
        if ($name === 'get') {
            return (new EloquentCollectionType)->resolve($context->codebase, $projected);
        }

        return $name === 'first' ? Type::union($projected, Type::null()) : $projected;
    }

    /** Accept only a physical field on the model's own statically known table. */
    private function physicalColumn(string $table, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }
        if (str_starts_with($column, $table.'.')) {
            $column = substr($column, strlen($table) + 1);
        }

        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column) && $this->schema?->column($table, $column) !== null
            ? $column
            : null;
    }

    /** Only refine the installed standard TModel or Collection<int, TModel> contract. */
    private function standardTerminalContract(ReturnTypeProviderContext $context, string $name): bool
    {
        $method = $context->codebase->getMethod(self::BUILDER, $name) ?? $context->codebase->getDeclaringMethod(
            self::BUILDER,
            $name,
        );
        if ($method === null || $method->declaredReturnType !== null) {
            return false;
        }
        $return = $method->returnType?->type;
        if ($name === 'get') {
            $collection = $return?->atomicTypes[0] ?? null;
            if (
                $return === null
                || count($return->atomicTypes) !== 1
                || ! $collection instanceof NamedObjectType
                || $collection->name !== 'Illuminate\\Database\\Eloquent\\Collection'
                || $collection->parameters === null
                || count($collection->parameters) !== 2
                || ! $context->types->equals($collection->parameters[0], Type::int())
            ) {
                return false;
            }
            $return = $collection->parameters[1];
        }
        if ($return === null) {
            return false;
        }
        $templates = 0;
        $nulls = 0;
        foreach ($return->atomicTypes as $type) {
            if ($type instanceof GenericParameterType && $type->name === 'TModel') {
                ++$templates;
            } elseif ($context->types->equals(Type::fromAtomic($type), Type::null())) {
                ++$nulls;
            } else {
                return false;
            }
        }

        return $templates === 1 && $nulls === ($name === 'first' ? 1 : 0);
    }

    private function concreteReturn(Type $type): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof MixedType) {
                continue;
            }
            if (
                $atom instanceof NamedObjectType
                && ! $atom->static
                && ! $atom->isThis
                && ($atom->intersections ?? []) === []
            ) {
                foreach ($atom->parameters ?? [] as $parameter) {
                    if (! $this->concreteReturn($parameter)) {
                        return false;
                    }
                }
            } elseif (! ExplicitGenericType::concrete(Type::fromAtomic($atom))) {
                return false;
            }
        }

        return true;
    }
}
