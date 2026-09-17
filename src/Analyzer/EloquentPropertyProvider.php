<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\AttributeTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CustomCastTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\SchemaIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\UnknownValue;
use Mago\Sdk\Analyzer\BeforeAnalysisContext;
use Mago\Sdk\Analyzer\BeforeAnalysisHook;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use PhpParser\Node;

final class EloquentPropertyProvider implements PropertyTypeProvider, InitializationHook, BeforeAnalysisHook
{
    private ?PhpSource $source = null;
    private ?SchemaIndex $schema = null;
    /** @var array<string, PropertyType|null> */
    private array $properties = [];
    /** @var array<string, array<string, string|UnknownValue>|UnknownValue> */
    private array $casts = [];
    /** @var array<string, string|null> */
    private array $tables = [];

    public function __construct(
        private readonly string $root,
    ) {}

    public function getTargets(): array
    {
        return [PropertyTarget::allProperties(ModelReflection::MODEL)];
    }

    public function initialize(InitializationContext $context): void
    {
        // Initialization is replayed for every worker and analysis generation.
        $this->source = null;
        $this->schema = null;
        $this->properties = $this->casts = $this->tables = [];
    }

    private function source(): PhpSource
    {
        if ($this->source === null) {
            $this->source = new PhpSource($this->root);
            $this->schema = new SchemaIndex($this->source);
            $this->schema->load();
        }

        return $this->source;
    }

    public function beforeAnalysis(BeforeAnalysisContext $context): void
    {
        $source = $this->source();
        $models = $context->codebase->getMultipleClasses($context->codebase->getClassDescendants(ModelReflection::MODEL));
        $anchor = null;
        foreach ($models as $model) {
            if ($model?->location->file === null || str_starts_with($model->location->file, '@')) {
                continue;
            }
            $anchor ??= $model->location;
            $source->read($model->location->file);
            // Include inherited casts and accessor files in metadata validation.
            foreach (['casts', 'getTable'] as $name) {
                $method = (new ModelReflection($context->codebase, $source))->customMethod($model->name, $name);
                if ($method !== null) {
                    (new ModelReflection($context->codebase, $source))->methodNode($method);
                }
            }
        }
        if ($anchor === null) {
            $anchor = $context->codebase->getClass(ModelReflection::MODEL)?->location;
        }
        foreach ($source->warnings as $path => $message) {
            if ($anchor !== null) {
                // External migrations need not be in Mago's source database. Anchor
                // the diagnostic to a known model and name the external file in a note.
                $context->report(Level::Warning, 'metadata-unavailable', Issue::at($message, $anchor)->withNote($path));
            }
        }
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $class = $context->access->class;
        $property = $context->access->property;
        $key = strtolower($class).'::$'.$property;
        if (! array_key_exists($key, $this->properties)) {
            $this->properties[$key] = $this->resolve($context->codebase, $class, $property);
        }

        return $this->properties[$key];
    }

    private function resolve(Codebase $codebase, string $class, string $property): ?PropertyType
    {
        if (
            strcasecmp($class, ModelReflection::MODEL) === 0
            || $codebase->getDeclaringProperty($class, '$'.$property) !== null
            || $codebase->getDeclaringMagicProperty($class, '$'.$property) !== null
        ) {
            return null;
        }
        $reflection = new ModelReflection($codebase, $this->source());
        foreach ([
            '__get',
            '__set',
            'getAttribute',
            'setAttribute',
            'getAttributeValue',
            'castAttribute',
            'resolveCasterClass',
            'getClassCastableAttributeValue',
            'setClassCastableAttribute',
        ] as $method) {
            if ($reflection->customMethod($class, $method) !== null) {
                return null;
            }
        }
        if (! array_key_exists($class, $this->tables)) {
            // SDK metadata queries may yield to another request in the same worker.
            // Publish both cache entries only after every nested request completes.
            $table = $reflection->table($class);
            $casts = $reflection->casts($class);
            $this->tables[$class] = $table;
            $this->casts[$class] = $casts;
        }
        $column = $this->schema?->column($this->tables[$class], $property);
        $casts = $this->casts[$class];
        $attribute = null;
        if (is_array($casts)) {
            if (array_key_exists($property, $casts)) {
                $cast = $casts[$property];
                $type = is_string($cast) ? AttributeTypes::cast($cast, $codebase) : null;
                $attribute = $type === null
                    ? (
                        is_string($cast)
                            ? CustomCastTypes::resolve(
                                $cast,
                                $codebase,
                                $column,
                                $this->source(),
                            )
                            : null
                    )
                    : AttributeTypes::nullable($type, $column?->nullable ?? true);
            } elseif ($column !== null) {
                $attribute = $reflection->automaticDate($class, $property)
                    ? AttributeTypes::nullable(AttributeTypes::date(), $column->nullable)
                    : AttributeTypes::column($column);
            }
        }
        $studly = ModelReflection::studly($property);
        $getter = $reflection->method($class, 'get'.$studly.'Attribute');
        $setter = $reflection->method($class, 'set'.$studly.'Attribute');
        $modern = $reflection->method($class, lcfirst($studly));
        $modernType = $modern?->returnType?->type ?? $modern?->declaredReturnType?->type;
        if (
            $modernType !== null
            && count($modernType->atomicTypes) === 1
            && $modernType->atomicTypes[0] instanceof NamedObjectType
            && strcasecmp($modernType->atomicTypes[0]->name, 'Illuminate\\Database\\Eloquent\\Casts\\Attribute') === 0
        ) {
            $parameters = $modernType->atomicTypes[0]->parameters ?? [];
            if (count($parameters) === 2) {
                return new PropertyType($parameters[0], $parameters[1]);
            }

            return null;
        }
        if ($getter !== null || $setter !== null) {
            $read = $getter === null
                ? $attribute?->readType
                : $getter->returnType?->type ?? $getter->declaredReturnType?->type;
            $parameter = $setter->parameters[0] ?? null;
            $write = $setter === null
                ? $attribute?->writeType
                : $parameter?->type?->type ?? $parameter?->declaredType?->type;
            // Do not turn an untyped accessor into an invented contract.
            if ($getter !== null && $read === null || $setter !== null && $write === null) {
                return null;
            }

            return $read === null && $write === null ? null : new PropertyType($read, $write);
        }
        if ($attribute !== null) {
            return $attribute;
        }
        // Casts and real columns take precedence over methods with the same name.
        if ($casts === UnknownValue::Value || array_key_exists($property, $casts) || $column !== null) {
            return null;
        }
        $relation = $reflection->method($class, $property);

        return $relation === null ? null : $this->relation($codebase, $reflection, $class, $relation);
    }

    private function relation(
        Codebase $codebase,
        ModelReflection $reflection,
        string $class,
        FunctionLikeMetadata $method,
    ): ?PropertyType {
        if ($method->static || $method->parameters !== []) {
            return null;
        }
        $type = $method->returnType?->type ?? $method->declaredReturnType?->type;
        $atom = $type !== null && count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        if (
            ! $atom instanceof NamedObjectType
            || ! str_starts_with($atom->name, 'Illuminate\\Database\\Eloquent\\Relations\\')
        ) {
            return null;
        }
        $kind = substr($atom->name, strlen('Illuminate\\Database\\Eloquent\\Relations\\'));
        $many = in_array(
            $kind,
            ['HasMany', 'HasManyThrough', 'BelongsToMany', 'MorphMany', 'MorphToMany', 'MorphedByMany'],
            true,
        );
        if (! $many && ! in_array($kind, ['HasOne', 'HasOneThrough', 'BelongsTo', 'MorphOne', 'MorphTo'], true)) {
            return null;
        }
        $related = $atom->parameters[0] ?? null;
        // An unbound native relation template is not a concrete related model.
        if (
            $related !== null
            && (count($related->atomicTypes) !== 1
            || ! $related->atomicTypes[0] instanceof NamedObjectType)
        ) {
            $related = null;
        }
        $withDefault = false;
        $expression = $reflection->returnExpression($method);
        if ($expression !== null) {
            [$receiver, $chain] = PhpSource::chain($expression);
            $factory = $chain[0] ?? null;
            $expected = lcfirst($kind);
            if (
                $receiver instanceof Node\Expr\Variable
                && $receiver->name === 'this'
                && $factory?->name instanceof Node\Identifier
                && strcasecmp($factory->name->toString(), $expected) === 0
            ) {
                $target = PhpSource::value(
                    PhpSource::argument($factory->args, 0, 'related'),
                    $method->identifier->class,
                    $class,
                );
                if (
                    $related === null
                    && $kind !== 'MorphTo'
                    && is_string($target)
                    && (
                        in_array(
                            strtolower(ModelReflection::MODEL),
                            array_map(strtolower(...), $codebase->getClassAncestors($target)),
                            true,
                        )
                        || strcasecmp($target, ModelReflection::MODEL) === 0
                    )
                ) {
                    $related = Type::namedObject($target);
                }
                foreach (array_slice($chain, 1) as $modifier) {
                    if (
                        $modifier->name instanceof Node\Identifier
                        && strtolower($modifier->name->toString()) === 'withdefault'
                    ) {
                        $argument = PhpSource::argument($modifier->args, 0, 'callback');
                        $value = $argument === null ? true : PhpSource::value($argument, $class);
                        $withDefault = $value === true || is_array($value) || $argument instanceof Node\Expr\Closure;
                    }
                }
            }
        }
        if ($related === null) {
            return null;
        }

        return new PropertyType(
            $many
                ? Type::namedObject('Illuminate\\Database\\Eloquent\\Collection', Type::int(), $related)
                : ($withDefault ? $related : Type::union($related, Type::null())),
        );
    }
}
