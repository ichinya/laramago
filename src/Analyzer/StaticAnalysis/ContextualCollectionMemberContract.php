<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\CollectionItemProperty;
use Ichinya\Laramago\Analyzer\EloquentChunkCallbackProvider;
use Ichinya\Laramago\Analyzer\EloquentPropertyProvider;
use Mago\Sdk\Analyzer\Argument;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\PropertyAccess;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NameContext;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** A source-backed contextual input supplements an omitted generic; it never replaces an explicit one. */
final class ContextualCollectionMemberContract
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';
    private const SUPPORT = 'Illuminate\\Support\\Collection';
    private const ENUMERABLE = 'Illuminate\\Support\\Enumerable';
    private const HAS_COLLECTION = 'Illuminate\\Database\\Eloquent\\HasCollection';

    // These are complete native bodies, with resolved class names. Unknown implementations defer.
    private const MODEL_BODIES = [
        'query' => 'return (new static())->newQuery();',
        'newQuery' => 'return $this->registerGlobalScopes($this->newQueryWithoutScopes());',
        'newModelQuery' => 'return $this->newEloquentBuilder($this->newBaseQueryBuilder())->setModel($this);',
        'newQueryWithoutScopes' => 'return $this->newModelQuery()->with($this->with)->withCount($this->withCount);',
        'newQueryWithoutRelationships' => 'return $this->registerGlobalScopes($this->newModelQuery());',
        'registerGlobalScopes' => 'foreach ($this->getGlobalScopes() as $identifier => $scope) { $builder->withGlobalScope($identifier, $scope); } return $builder;',
        'newEloquentBuilder' => '$builderClass = $this->resolveCustomBuilderClass(); if ($builderClass && is_subclass_of($builderClass, \\Illuminate\\Database\\Eloquent\\Builder::class)) { return new $builderClass($query); } return new static::$builder($query);',
        'newInstance' => '$model = new static(); $model->exists = $exists; $model->setConnection($this->getConnectionName()); $model->setTable($this->getTable()); $model->mergeCasts($this->casts); $model->fill((array) $attributes); return $model;',
        'newFromBuilder' => '$model = $this->newInstance([], true); $model->setRawAttributes((array) $attributes, true); $model->setConnection($connection ?? $this->getConnectionName()); $model->fireModelEvent("retrieved", false); return $model;',
        'resolveCustomBuilderClass' => 'return static::resolveClassAttribute(\\Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder::class, "builderClass") ?? false;',
        'newBaseQueryBuilder' => 'return $this->getConnection()->query();',
    ];
    private const BUILDER_BODIES = [
        'setModel' => '$this->model = $model; $this->query->from($model->getTable()); return $this;',
        'getModels' => 'return $this->model->hydrate($this->query->get($columns)->all())->all();',
        'newModelInstance' => '$attributes = array_merge($this->pendingAttributes, $attributes); return $this->model->newInstance($attributes)->setConnection($this->query->getConnection()->getName());',
        'hydrate' => '$instance = $this->newModelInstance(); return $instance->newCollection(array_map(function ($item) use ($items, $instance) { $model = $instance->newFromBuilder($item); if (count($items) > 1) { $model->preventsLazyLoading = \\Illuminate\\Database\\Eloquent\\Model::preventsLazyLoading(); } return $model; }, $items));',
        'where' => 'if ($column instanceof \\Closure && is_null($operator)) { $column($query = $this->model->newQueryWithoutRelationships()); $this->eagerLoad = array_merge($this->eagerLoad, $query->getEagerLoads()); $this->withoutGlobalScopes($query->removedScopes()); $this->query->addNestedWhereQuery($query->getQuery(), $boolean); } else { $this->query->where(...func_get_args()); } return $this;',
        'with' => 'if ($callback instanceof \\Closure) { $eagerLoad = $this->parseWithRelations([$relations => $callback]); } else { $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations); } $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad); return $this;',
    ];

    private PhpSource $source;
    private EloquentPropertyProvider $properties;
    /** @var array<string, string> */
    private array $snapshots = [];
    /** @var array<string, Node\Stmt\ClassLike|null> */
    private array $classes = [];

    public function __construct(private readonly string $root, private readonly ContextualCollectionMembers $members)
    {
        $this->source = new PhpSource($root);
        $this->properties = new EloquentPropertyProvider($root);
    }

    /** Scalar observations from a source-domain check, not receiver inference or replacement. */
    public array $issueStages = [];

    /** @param array<string, mixed> $proof */
    public function read(PropertyTypeProviderContext $context, array $proof): ?Type
    {
        return $this->sourceRead($context, $proof, $context->access->property, $context->access->span);
    }

    /** The genuine issue supplies native authority; the source proof supplies the candidate field. */
    public function readIssue(IssueFilterContext $context, array $proof): ?Type
    {
        $fetch = $proof['fetch'];
        return $this->sourceRead($context, $proof, $fetch->name->name,
            new Span($fetch->getStartFilePos(), $fetch->getEndFilePos() + 1));
    }

    private function sourceRead(PropertyTypeProviderContext|IssueFilterContext $context, array $proof, string $field, Span $span): ?Type
    {
        $this->issueStages = [];
        $context->cancellation->throwIfCancelled();
        $codebase = $context->codebase;
        $this->activeCodebase = $codebase;
        $model = $proof['model'];
        $modelOkay = is_string($model) && strcasecmp($model, self::MODEL) !== 0
            && ($codebase->getClassLike($model)?->templates ?? []) === []
            && $context->types->isContainedBy(Type::namedObject($model), Type::namedObject(self::MODEL));
        $this->issueStages['modelDomain'] = $modelOkay;
        if (! $modelOkay) { return null; }
        // Preserve the existing short-circuit order and every source/native condition.
        foreach (['hierarchy' => [$codebase, $model], 'caller' => [$codebase, $proof],
            'nativeQuery' => [$codebase, $model, $proof], 'modelBinding' => [$context],
            'collection' => [$context, $model], 'input' => [$context, $proof],
            'readonlyInputs' => [$context, $proof]] as $guard => $arguments) {
            $guardOkay = $this->$guard(...$arguments);
            $this->issueStages[$guard] = $guardOkay;
            if (! $guardOkay) { return null; }
        }
        // A real base-class field always takes precedence over a contextual magic member.
        $baseFieldClear = $codebase->getDeclaringProperty(self::MODEL, '$'.$field) === null
            && $codebase->getDeclaringMagicProperty(self::MODEL, '$'.$field) === null;
        $this->issueStages['baseFieldClear'] = $baseFieldClear;
        if (! $baseFieldClear) { return null; }
        $physical = $codebase->getDeclaringProperty($model, '$'.$field);
        $property = $physical ?? $codebase->getDeclaringMagicProperty($model, '$'.$field);
        $visibleProperty = $property !== null && ltrim($property->name, '$') === $field
            && $property->readVisibility === Visibility::Public && $property->hooks === []
            && ! $property->flags->contains(MetadataFlags::STATIC) && ! $property->flags->contains(MetadataFlags::WRITEONLY);
        $this->issueStages['visibleProperty'] = $visibleProperty;
        if (! $visibleProperty) { return null; }
        $metadata = $property->type ?? $property->declaredType;
        $type = $metadata?->type;
        $concreteCurrentReadMetadata = $type !== null && (string) $type !== 'never' && CollectionItemProperty::concrete($type)
            && $metadata->location !== null && $metadata->location->file !== null && $this->retain($metadata->location->file);
        $this->issueStages['concreteCurrentReadMetadata'] = $concreteCurrentReadMetadata;
        if (! $concreteCurrentReadMetadata) { return null; }
        $documentedSourceType = $this->documentedType($context, $metadata);
        $this->issueStages['documentedSourceType'] = $documentedSourceType;
        if (! $documentedSourceType) { return null; }
        $currentPhysicalOrMagicName = $physical === null ? $this->magicName($metadata, $field) : $this->physical($physical);
        $this->issueStages['currentPhysicalOrMagicName'] = $currentPhysicalOrMagicName;
        if (! $currentPhysicalOrMagicName) { return null; }
        if ($physical === null) {
            $read = $this->properties->getPropertyType(new PropertyTypeProviderContext(
                $context->phpVersion, $codebase,
                new PropertyAccess($model, $field, PropertyAccessKind::Read,
                    Type::namedObject($model), $span),
                $context->types, $context->cancellation,
            ))?->readType;
            if ($read !== null) {
                if ((string) $read === 'never' || ! CollectionItemProperty::concrete($read)) { return null; }
                $type = $read;
            }
        }
        $currentDocumentedRead = $this->current();
        $this->issueStages['currentDocumentedRead'] = $currentDocumentedRead; return $currentDocumentedRead ? $type : null;
    }

    /** @param array<string, mixed> $proof */
    private function caller(Codebase $codebase, array $proof): bool
    {
        $scope = $proof['scope'];
        if (! $scope instanceof Node\Stmt\ClassMethod && ! $scope instanceof Node\Stmt\Function_) { return false; }
        $method = $proof['owner'] === null ? $codebase->getFunction($scope->namespacedName?->toString() ?? $scope->name->name)
            : ($codebase->getMethod($proof['owner'], $scope->name->name) ?? $codebase->getDeclaringMethod($proof['owner'], $scope->name->name));
        return $method !== null && $this->methodMatches($method, $scope, $proof['file'])
            && ($proof['owner'] === null || $this->hierarchy($codebase, $proof['owner']));
    }

    /** @param array<string, mixed> $proof */
    private function nativeQuery(Codebase $codebase, string $model, array $proof): bool
    {
        foreach (self::MODEL_BODIES as $name => $body) {
            if (! $this->native($codebase, $model, $name, self::MODEL, '/Database/Eloquent/Model.php', $body)) { return false; }
        }
        $query = $codebase->getMethod($model, 'query') ?? $codebase->getDeclaringMethod($model, 'query');
        $return = $query?->returnType?->type;
        $builder = $return?->atomicTypes[0] ?? null;
        $element = $builder instanceof NamedObjectType ? ($builder->parameters[0]->atomicTypes[0] ?? null) : null;
        if ($query === null || ! $query->static || ! $query->returnType?->fromDocblock || count($return?->atomicTypes ?? []) !== 1
            || ! $builder instanceof NamedObjectType || strcasecmp($builder->name, self::BUILDER) !== 0 || count($builder->parameters ?? []) !== 1
            || ! $element instanceof NamedObjectType || strcasecmp($element->name, self::MODEL) !== 0 || ! $element->static
            || count($builder->parameters[0]->atomicTypes) !== 1 || ($element->parameters ?? []) !== [] || ($element->intersections ?? []) !== []
            || preg_match('~@return\s+\\\\Illuminate\\\\Database\\\\Eloquent\\\\Builder\s*<\s*static\s*>~', $this->methodNode($query)?->getDocComment()?->getText() ?? '') !== 1) { return false; }
        foreach (['__construct', '__call', '__callStatic', 'newBaseQueryBuilder', 'resolveCustomBuilderClass'] as $name) {
            $method = $codebase->getMethod($model, $name) ?? $codebase->getDeclaringMethod($model, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::MODEL) !== 0 || $this->methodNode($method) === null) { return false; }
        }
        $reflection = new ModelReflection($codebase, $this->source);
        if (strcasecmp((string) $reflection->default($model, 'builder'), self::BUILDER) !== 0) { return false; }
        foreach (array_unique([$model, ...$codebase->getClassAncestors($model)]) as $ancestor) {
            $metadata = $codebase->getClassLike($ancestor);
            if ($metadata?->flags->contains(MetadataFlags::BUILTIN)) { continue; }
            $node = $metadata === null ? null : $this->classNode($codebase, $metadata);
            if ($node === null) { return false; }
            foreach ($metadata->attributes as $attribute) {
                if (in_array(strtolower($attribute->name), [strtolower('Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder'), strtolower('Illuminate\\Database\\Eloquent\\Attributes\\ScopedBy')], true)) { return false; }
            }
            if (strcasecmp($ancestor, self::MODEL) === 0 || str_starts_with(strtolower($ancestor), 'illuminate\\')) { continue; }
            foreach ((new NodeFinder)->findInstanceOf([$node], Node\Expr\MethodCall::class) as $call) {
                if (! $call->name instanceof Node\Identifier || in_array(strtolower($call->name->name), ['addglobalscope', 'setmodel', 'resolverelationusing'], true)) { return false; }
            }
            foreach ((new NodeFinder)->findInstanceOf([$node], Node\Expr\StaticCall::class) as $call) {
                if (! $call->name instanceof Node\Identifier || in_array(strtolower($call->name->name), ['addglobalscope', 'resolverelationusing'], true)) { return false; }
            }
            foreach ((new NodeFinder)->findInstanceOf([$node], Node\Expr\StaticPropertyFetch::class) as $property) {
                if (! $property->name instanceof Node\VarLikeIdentifier || in_array($property->name->name, ['builder', 'collectionClass', 'resolvedCollectionClasses'], true)) { return false; }
            }
        }
        foreach (self::BUILDER_BODIES as $name => $body) {
            if (! $this->native($codebase, self::BUILDER, $name, self::BUILDER, '/Database/Eloquent/Builder.php', $body)) { return false; }
        }
        // Mutable builder aliases and closures capable of changing its model are excluded by the source index.
        foreach ((new NodeFinder)->findInstanceOf($proof['scope']->stmts ?? [], Node\Expr\Variable::class) as $variable) {
            if ($variable->name !== $proof['query']) { continue; }
            $call = ContextualCollectionMembers::parent($proof['scope'], $variable);
            if (! $call instanceof Node\Expr\MethodCall || $call->var !== $variable || ! $call->name instanceof Node\Identifier) { continue; }
            if (strtolower($call->name->name) === 'where') {
                $column = $call->args[0] ?? null;
                if (! $column instanceof Node\Arg || ! $column->value instanceof Node\Scalar\String_) { return false; }
            }
            foreach ($call->args as $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef
                    || $argument->value instanceof Node\Expr\Closure || $argument->value instanceof Node\Expr\ArrowFunction) {
                    if ($argument instanceof Node\Arg && $argument->value === $proof['callback']) { continue; }
                    return false;
                }
            }
        }
        return true;
    }

    /** The native setter must retain the exact model and admit its declared template input. */
    private function modelBinding(PropertyTypeProviderContext|IssueFilterContext $context): bool
    {
        $method = $context->codebase->getMethod(self::BUILDER, 'setModel')
            ?? $context->codebase->getDeclaringMethod(self::BUILDER, 'setModel');
        $node = $method === null ? null : $this->methodNode($method);
        $parameter = $method?->parameters[0] ?? null;
        $syntax = $node?->params[0] ?? null;
        $formal = $parameter?->type?->type;
        $generic = $formal?->atomicTypes[0] ?? null;
        $return = $method?->returnType?->type;
        $builder = $return?->atomicTypes[0] ?? null;
        $doc = $node?->getDocComment()?->getText() ?? '';
        if ($method === null || $node === null || $method->static || $method->visibility !== Visibility::Public
            || count($method->parameters) !== 1 || count($method->templates) !== 1 || $parameter === null
            || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
            || $parameter->outType !== null || $parameter->closureThisType !== null || $parameter->defaultType !== null
            || ! $syntax?->type instanceof Node\Name || strcasecmp($syntax->type->toString(), self::MODEL) !== 0
            || $syntax->default !== null || ! $context->types->equals($parameter->declaredType?->type ?? Type::mixed(), Type::namedObject(self::MODEL))
            || ! $parameter->type?->fromDocblock || count($formal?->atomicTypes ?? []) !== 1 || ! $generic instanceof GenericParameterType
            || $generic->name !== 'TModelNew' || $generic->definingEntity->kind !== GenericParentKind::FunctionLike
            || strcasecmp($generic->definingEntity->name, self::BUILDER) !== 0 || strcasecmp($generic->definingEntity->member ?? '', 'setModel') !== 0
            || ($generic->intersections ?? []) !== [] || ! $context->types->equals($generic->constraint, Type::namedObject(self::MODEL))
            || $method->templates[0]->name !== 'TModelNew' || ! $context->types->equals($method->templates[0]->constraint, $generic->constraint)
            || ! $method->returnType?->fromDocblock || count($return?->atomicTypes ?? []) !== 1 || ! $builder instanceof NamedObjectType
            || strcasecmp($builder->name, self::BUILDER) !== 0 || ! $builder->static || $builder->isThis || $builder->remappedParameters
            || ($builder->intersections ?? []) !== [] || count($builder->parameters ?? []) !== 1
            || ! $context->types->equals($builder->parameters[0], $formal)
            || preg_match('~@template\s+TModelNew\s+of\s+\\\\Illuminate\\\\Database\\\\Eloquent\\\\Model\b~', $doc) !== 1
            || preg_match('~@param\s+TModelNew\s+\$model\b~', $doc) !== 1
            || preg_match('~@return\s+static\s*<\s*TModelNew\s*>~', $doc) !== 1) { return false; }
        return true;
    }

    private function collection(PropertyTypeProviderContext|IssueFilterContext $context, string $model): bool
    {
        $codebase = $context->codebase;
        if (! $this->hierarchy($codebase, self::COLLECTION)) { return false; }
        $reflection = new ModelReflection($codebase, $this->source);
        if (strcasecmp((string) $reflection->default($model, 'collectionClass'), self::COLLECTION) !== 0) { return false; }
        foreach ([$model, ...$codebase->getClassAncestors($model)] as $ancestor) {
            $metadata = $codebase->getClassLike($ancestor);
            if ($metadata === null) { return false; }
            foreach ($metadata->attributes as $attribute) {
                if (strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\CollectedBy') === 0) { return false; }
            }
        }
        $method = $codebase->getMethod($model, 'newCollection') ?? $codebase->getDeclaringMethod($model, 'newCollection');
        $body = 'static::$resolvedCollectionClasses[static::class] ??= ($this->resolveCollectionFromAttribute() ?? static::$collectionClass); $collection = new static::$resolvedCollectionClasses[static::class]($models); if (\\Illuminate\\Database\\Eloquent\\Model::isAutomaticallyEagerLoadingRelationships()) { $collection->withRelationshipAutoloading(); } return $collection;';
        if ($method === null || ! $this->native($codebase, $model, 'newCollection', self::HAS_COLLECTION, '/Database/Eloquent/HasCollection.php', $body)) { return false; }
        foreach (['resolveCollectionFromAttribute' => self::HAS_COLLECTION, 'getGlobalScopes' => 'Illuminate\\Database\\Eloquent\\Concerns\\HasGlobalScopes'] as $name => $owner) {
            $method = $codebase->getMethod($model, $name) ?? $codebase->getDeclaringMethod($model, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0 || $this->methodNode($method) === null) { return false; }
        }
        if (! $this->native($codebase, self::COLLECTION, 'getIterator', self::SUPPORT, '/Collections/Collection.php', 'return new \\ArrayIterator($this->items);')) { return false; }
        if (! $this->collectionConstructor($codebase)) { return false; }
        $collection = $codebase->getClassLike(self::COLLECTION);
        $collectionNode = $collection === null ? null : $this->classNode($codebase, $collection);
        $support = $codebase->getClassLike(self::SUPPORT);
        $supportNode = $support === null ? null : $this->classNode($codebase, $support);
        if ($collectionNode === null || $supportNode === null
            || preg_match('~@extends\s+\\\\Illuminate\\\\Support\\\\Collection\s*<\s*TKey\s*,\s*TModel\s*>~', $collectionNode->getDocComment()?->getText() ?? '') !== 1) { return false; }
        $directIterator = preg_match('~@implements\s+\\\\IteratorAggregate\s*<\s*TKey\s*,\s*TValue\s*>~', $supportNode->getDocComment()?->getText() ?? '') === 1;
        if (! $directIterator) {
            $enumerable = $codebase->getClassLike('Illuminate\\Support\\Enumerable');
            $enumerableNode = $enumerable === null ? null : $this->classNode($codebase, $enumerable);
            if ($enumerableNode === null || $enumerable->kind !== ClassLikeKind::Interface
                || preg_match('~@implements\s+\\\\Illuminate\\\\Support\\\\Enumerable\s*<\s*TKey\s*,\s*TValue\s*>~', $supportNode->getDocComment()?->getText() ?? '') !== 1
                || preg_match('~@extends\s+\\\\IteratorAggregate\s*<\s*TKey\s*,\s*TValue\s*>~', $enumerableNode->getDocComment()?->getText() ?? '') !== 1) { return false; }
        }
        return $collection !== null && count($collection->templates) === 2
            && $collection->templates[0]->name === 'TKey' && $collection->templates[1]->name === 'TModel'
            && $context->types->isContainedBy(Type::int(), $collection->templates[0]->constraint)
            && $context->types->isContainedBy(Type::namedObject($model), $collection->templates[1]->constraint);
    }

    /** The known array branch must preserve the exact hydrated elements before native iteration. */
    private function collectionConstructor(Codebase $codebase): bool
    {
        $constructor = $codebase->getMethod(self::COLLECTION, '__construct') ?? $codebase->getDeclaringMethod(self::COLLECTION, '__construct');
        $node = $constructor === null ? null : $this->methodNode($constructor);
        if ($node === null || strcasecmp($constructor->identifier->class ?? '', self::SUPPORT) !== 0
            || $constructor->static || $constructor->visibility !== Visibility::Public || count($constructor->parameters) !== 1
            || $constructor->parameters[0]->name !== '$items' || $constructor->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE)) { return false; }
        if (self::body($node->stmts ?? []) === self::body((new ParserFactory)->createForNewestSupportedVersion()->parse('<?php $this->items = $items;') ?? [])) { return true; }
        if (! $this->native($codebase, self::COLLECTION, '__construct', self::SUPPORT, '/Collections/Collection.php', '$this->items = $this->getArrayableItems($items);')
            || ! $this->native($codebase, self::COLLECTION, 'getArrayableItems', 'Illuminate\\Support\\Traits\\EnumeratesValues', '/Collections/Traits/EnumeratesValues.php',
                'return is_null($items) || is_scalar($items) || $items instanceof \\UnitEnum ? \\Illuminate\\Support\\Arr::wrap($items) : \\Illuminate\\Support\\Arr::from($items);')
            || ! $this->native($codebase, 'Illuminate\\Support\\Arr', 'wrap', 'Illuminate\\Support\\Arr', '/Collections/Arr.php',
                'if (is_null($value)) { return []; } return is_array($value) ? $value : [$value];')) { return false; }
        $from = $codebase->getMethod('Illuminate\\Support\\Arr', 'from') ?? $codebase->getDeclaringMethod('Illuminate\\Support\\Arr', 'from');
        $fromNode = $from === null ? null : $this->methodNode($from);
        $return = $fromNode?->stmts[0] ?? null;
        $match = $return instanceof Node\Stmt\Return_ ? $return->expr : null;
        $arm = $match instanceof Node\Expr\Match_ ? ($match->arms[0] ?? null) : null;
        $condition = $arm?->conds[0] ?? null;
        if ($fromNode === null || count($fromNode->stmts ?? []) !== 1 || $from->identifier->class !== 'Illuminate\\Support\\Arr'
            || ! $from->static || $from->visibility !== Visibility::Public || count($from->parameters) !== 1 || $from->parameters[0]->name !== '$items'
            || ! $match->cond instanceof Node\Expr\ConstFetch || strtolower($match->cond->name->toString()) !== 'true'
            || count($arm?->conds ?? []) !== 1 || ! $condition instanceof Node\Expr\FuncCall || ! $condition->name instanceof Node\Name
            || $condition->name->toString() !== 'is_array' || count($condition->args) !== 1
            || ! $condition->args[0] instanceof Node\Arg || $condition->args[0]->byRef || $condition->args[0]->unpack
            || ! $condition->args[0]->value instanceof Node\Expr\Variable || $condition->args[0]->value->name !== 'items'
            || ! $arm->body instanceof Node\Expr\Variable || $arm->body->name !== 'items') { return false; }
        foreach (['Illuminate\\Support\\is_array', 'Illuminate\\Support\\is_null', 'Illuminate\\Support\\Traits\\is_null', 'Illuminate\\Support\\Traits\\is_scalar'] as $name) {
            if ($codebase->getFunction($name) !== null) { return false; }
        }
        return true;
    }

    /** @param array<string, mixed> $proof */
    private function input(PropertyTypeProviderContext|IssueFilterContext $context, array $proof): bool
    {
        $contents = @file_get_contents($proof['file']);
        if ($contents === false || count($proof['callback']->params) > 2) { return false; }
        $native = $context->codebase->getMethod(self::BUILDER, $proof['call']->name->name)
            ?? $context->codebase->getDeclaringMethod(self::BUILDER, $proof['call']->name->name);
        $nativeCallback = $native?->parameters[1]->type?->type;
        $callable = $nativeCallback?->atomicTypes[0] ?? null;
        $parameter = $callable instanceof CallableType ? ($callable->signature?->parameters[0] ?? null) : null;
        $collection = $parameter?->type?->atomicTypes[0] ?? null;
        $element = $collection instanceof NamedObjectType ? ($collection->parameters[1]->atomicTypes[0] ?? null) : null;
        if ($native === null || count($nativeCallback?->atomicTypes ?? []) !== 1 || ! $callable instanceof CallableType
            || $callable->signature === null || count($callable->signature->parameters) !== 2
            || $parameter === null || $parameter->byReference || $parameter->variadic || $parameter->hasDefault
            || count($parameter->type?->atomicTypes ?? []) !== 1 || ! $collection instanceof NamedObjectType
            || strcasecmp($collection->name, self::SUPPORT) !== 0 || count($collection->parameters ?? []) !== 2
            || ! $context->types->equals($collection->parameters[0], Type::int())
            || count($collection->parameters[1]->atomicTypes) !== 1 || ! $element instanceof GenericParameterType
            || $element->name !== 'TValue' || strcasecmp($element->definingEntity->name, 'Illuminate\\Database\\Concerns\\BuildsQueries') !== 0
            || ($element->intersections ?? []) !== [] || (string) $element->constraint !== 'mixed') { return false; }
        $builderMetadata = $context->codebase->getClassLike(self::BUILDER);
        $builderNode = $builderMetadata === null ? null : $this->classNode($context->codebase, $builderMetadata);
        $traitUse = false;
        foreach ($builderNode?->stmts ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse
                && preg_match('~@use\s+\\\\Illuminate\\\\Database\\\\Concerns\\\\BuildsQueries\s*<\s*TModel\s*>~', $statement->getDocComment()?->getText() ?? '') === 1) { $traitUse = true; }
        }
        if (! $traitUse) { return false; }
        $arguments = [];
        foreach ($proof['call']->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) { return false; }
            $arguments[] = new Argument($argument->name?->name, false, false,
                new Span($argument->value->getStartFilePos(), $argument->value->getEndFilePos() + 1),
                substr($contents, $argument->value->getStartFilePos(), $argument->value->getEndFilePos() - $argument->value->getStartFilePos() + 1), null);
        }
        $invocation = new Invocation(InvocationKind::InstanceMethod, $proof['call']->name->name, self::BUILDER,
            Type::namedObject(self::BUILDER, Type::namedObject($proof['model'])),
            new Span($proof['call']->getStartFilePos(), $proof['call']->getEndFilePos() + 1), $arguments);
        $effective = (new EloquentChunkCallbackProvider($this->root))->getCallableSignature(new CallableSignatureProviderContext(
            $context->phpVersion, $context->codebase, $invocation, $context->types, $context->cancellation));
        $type = $effective?->parameters[1]->type;
        $atom = $type?->atomicTypes[0] ?? null;
        $input = $atom instanceof CallableType ? ($atom->signature?->parameters[0]->type ?? null) : null;
        $expected = Type::namedObject(self::COLLECTION, Type::int(), Type::namedObject($proof['model']));
        if ($input === null || ! $context->types->equals($input, $expected)) { return false; }
        $parameter = $proof['callback']->params[1] ?? null;
        if ($parameter !== null && ($parameter->byRef || $parameter->variadic || $parameter->default !== null
            || $parameter->type !== null && (! $parameter->type instanceof Node\Identifier || $parameter->type->name !== 'int'))) { return false; }
        // The native nominal formal has no written generic positions. Its declared bounds must admit the actual input.
        return $proof['parameter']->type instanceof Node\Name && strcasecmp($proof['parameter']->type->toString(), self::COLLECTION) === 0;
    }

    /** @param array<string, mixed> $proof */
    private function readonlyInputs(PropertyTypeProviderContext|IssueFilterContext $context, array $proof): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($proof['callback']->stmts, Node\Expr\Variable::class) as $variable) {
            if ($variable->name !== $proof['input']) { continue; }
            $argument = ContextualCollectionMembers::parent($proof['callback'], $variable);
            if ($argument === $proof['loop'] && $proof['loop']->expr === $variable) { continue; }
            $call = $argument instanceof Node\Arg ? ContextualCollectionMembers::parent($proof['callback'], $argument) : null;
            if (! $call instanceof Node\Expr\MethodCall || ! $call->var instanceof Node\Expr\Variable
                || $call->var->name !== 'this' || ! $call->name instanceof Node\Identifier || $proof['owner'] === null) { return false; }
            $method = $context->codebase->getMethod($proof['owner'], $call->name->name)
                ?? $context->codebase->getDeclaringMethod($proof['owner'], $call->name->name);
            $index = array_search($argument, $call->args, true);
            if ($method === null || ! is_int($index) || $argument->name !== null
                || $method->visibility === Visibility::Private && strcasecmp($method->identifier->class ?? '', $proof['owner']) !== 0
                || ! $this->readonlyHelper($context, $method, $index, $proof['model'])) { return false; }
            foreach ($context->codebase->getClassDescendants($proof['owner']) as $descendant) {
                $bound = $context->codebase->getMethod($descendant, $call->name->name) ?? $context->codebase->getDeclaringMethod($descendant, $call->name->name);
                if ($bound === null || $bound->identifier->class !== $method->identifier->class || ! $this->hierarchy($context->codebase, $descendant)) { return false; }
            }
        }
        return true;
    }

    private function readonlyHelper(PropertyTypeProviderContext|IssueFilterContext $context, FunctionLikeMetadata $method, int $index, string $model): bool
    {
        $node = $this->methodNode($method);
        $parameter = $method->parameters[$index] ?? null;
        $syntax = $node?->params[$index] ?? null;
        $expected = Type::namedObject(self::COLLECTION, Type::int(), Type::namedObject($model));
        $formal = $parameter?->type?->type ?? $parameter?->declaredType?->type;
        if ($node === null || $node->stmts === null || $method->abstract || $method->static || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || $parameter === null || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
            || $parameter->outType !== null || $parameter->flags->contains(MetadataFlags::VARIADIC)
            || $formal === null || ! $context->types->equals($formal, $expected)
            || ! $syntax?->var instanceof Node\Expr\Variable || ! is_string($syntax->var->name)) { return false; }
        $typeMetadata = $parameter->type ?? $parameter->declaredType;
        if ($typeMetadata === null || ! $this->documentedType($context, $typeMetadata)) { return false; }
        $local = $syntax->var->name;
        foreach ((new NodeFinder)->findInstanceOf($node->stmts ?? [], Node\Expr\Variable::class) as $variable) {
            if ($variable->name !== $local) { continue; }
            $parent = ContextualCollectionMembers::parent($node, $variable);
            if (! $parent instanceof Node\Stmt\Foreach_ || $parent->expr !== $variable || $parent->byRef
                || ! $parent->valueVar instanceof Node\Expr\Variable || ! is_string($parent->valueVar->name)) { return false; }
            $item = $parent->valueVar->name;
            foreach ((new NodeFinder)->findInstanceOf($parent->stmts, Node\Expr\Variable::class) as $read) {
                if ($read->name !== $item) { continue; }
                $access = ContextualCollectionMembers::parent($parent, $read);
                if (! $access instanceof Node\Expr\PropertyFetch || $access->var !== $read || ! $access->name instanceof Node\Identifier
                    || ContextualCollectionMembers::isWritten($parent, $access)) { return false; }
            }
        }
        return (new NodeFinder)->findFirst([$node], static fn (Node $part): bool =>
            $part instanceof Node\Stmt\Global_ || $part instanceof Node\Expr\Eval_ || $part instanceof Node\Expr\Include_
            || $part instanceof Node\Expr\Variable && ! is_string($part->name)) === null;
    }

    private function hierarchy(Codebase $codebase, string $class): bool
    {
        $metadata = $codebase->getClassLike($class);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) { return false; }
        foreach (array_unique([$class, ...$codebase->getClassAncestors($class)]) as $ancestor) {
            $bound = $codebase->getClassLike($ancestor);
            if ($bound === null || $bound->hasIncompleteHierarchy()) { return false; }
            if (! $bound->flags->contains(MetadataFlags::BUILTIN) && $this->classNode($codebase, $bound) === null) { return false; }
            foreach ($bound->mixins as $mixin) {
                $atom = $mixin->atomicTypes[0] ?? null;
                if (count($mixin->atomicTypes) !== 1 || ! $atom instanceof NamedObjectType || strcasecmp($atom->name, self::MODEL) !== 0
                    || ($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== [] || $atom->static || $atom->isThis || $atom->remappedParameters) { return false; }
            }
        }
        return true;
    }

    private function classNode(Codebase $codebase, ClassLikeMetadata $metadata): ?Node\Stmt\ClassLike
    {
        if ($metadata->location->file === null || ! $this->retain($metadata->location->file)) { return null; }
        $key = $metadata->name.':'.hash('sha256', serialize($metadata));
        if (array_key_exists($key, $this->classes)) { return $this->classes[$key]; }
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->members->path($metadata->location->file)) ?? [], Node\Stmt\ClassLike::class) as $node) {
            if ($node->name === null || $node->namespacedName === null || strcasecmp($node->namespacedName->toString(), $metadata->name) !== 0) { continue; }
            if ($metadata->location->span->end !== $node->getEndFilePos() + 1 || $metadata->nameLocation?->span->start !== $node->name->getStartFilePos()
                || $metadata->nameLocation?->span->end !== $node->name->getEndFilePos() + 1
                || ($node instanceof Node\Stmt\Class_ ? ClassLikeKind::Class_ : ($node instanceof Node\Stmt\Trait_ ? ClassLikeKind::Trait : ClassLikeKind::Interface)) !== $metadata->kind) { return null; }
            if ($node instanceof Node\Stmt\Class_ && (strcasecmp($node->extends?->toString() ?? '', $metadata->directParentClass ?? '') !== 0
                || $node->isAbstract() !== $metadata->flags->contains(MetadataFlags::ABSTRACT)
                || $node->isFinal() !== $metadata->flags->contains(MetadataFlags::FINAL))) { return null; }
            foreach ($node->stmts as $statement) {
                if (! $statement instanceof Node\Stmt\TraitUse) { continue; }
                if ($statement->adaptations !== [] && ! $this->nativeUnrelatedAlias($codebase, $metadata, $statement)) { return null; }
                foreach ($statement->traits as $trait) {
                    if (! in_array(strtolower($trait->toString()), array_map(strtolower(...), $metadata->usedTraits), true)) { return null; }
                }
            }
            foreach ($node->getMethods() as $method) {
                $bound = $codebase->getMethod($metadata->name, $method->name->name) ?? $codebase->getDeclaringMethod($metadata->name, $method->name->name);
                if ($bound === null || strcasecmp($bound->identifier->class ?? '', $metadata->name) !== 0 || ! $this->methodMatches($bound, $method, $metadata->location->file)) { return null; }
            }
            foreach ($node->getProperties() as $declaration) {
                foreach ($declaration->props as $property) {
                    $bound = $codebase->getDeclaringProperty($metadata->name, '$'.$property->name->name);
                    if ($bound === null || $bound->nameLocation?->span->start !== $property->name->getStartFilePos()
                        || $bound->nameLocation?->span->end !== $property->name->getEndFilePos() + 1) { return null; }
                    if (in_array($property->name->name, ['builder', 'collectionClass', 'resolvedCollectionClasses'], true)) {
                        $actual = $property->default === null ? null : PhpSource::value($property->default, $metadata->name);
                        if ($actual === UnknownValue::Value || (new ModelReflection($codebase, $this->source))->default($metadata->name, $property->name->name) !== $actual) { return null; }
                    }
                }
            }
            return $this->classes[$key] = $node;
        }
        return $this->classes[$key] = null;
    }

    /** A known native alias does not change any member consumed by this proof. */
    private function nativeUnrelatedAlias(Codebase $codebase, ClassLikeMetadata $owner, Node\Stmt\TraitUse $use): bool
    {
        if (strcasecmp($owner->name, self::BUILDER) !== 0 || $owner->kind !== ClassLikeKind::Class_
            || ! str_ends_with(strtolower($this->members->path($owner->location->file ?? '')), '/laravel/framework/src/illuminate/database/eloquent/builder.php')
            || count($use->adaptations) !== 1) { return false; }
        $alias = $use->adaptations[0];
        $trait = 'Illuminate\\Database\\Concerns\\BuildsQueries';
        if (! $alias instanceof Node\Stmt\TraitUseAdaptation\Alias || $alias->trait === null
            || strcasecmp($alias->trait->toString(), $trait) !== 0 || strcasecmp($alias->method->name, 'sole') !== 0
            || $alias->newName === null || strcasecmp($alias->newName->name, 'baseSole') !== 0 || $alias->newModifier !== null
            || ! in_array(strtolower($trait), array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $use->traits), true)) { return false; }
        $original = $codebase->getMethod($trait, 'sole') ?? $codebase->getDeclaringMethod($trait, 'sole');
        $bound = $codebase->getDeclaringMethod(self::BUILDER, 'baseSole');
        if ($original === null || $bound === null || strcasecmp($original->identifier->class ?? '', $trait) !== 0
            || strcasecmp($original->identifier->name, 'sole') !== 0 || serialize($bound) !== serialize($original)
            || $this->methodNode($original) === null) { return false; }
        foreach (array_keys(self::BUILDER_BODIES) as $name) {
            $guarded = $codebase->getMethod(self::BUILDER, $name) ?? $codebase->getDeclaringMethod(self::BUILDER, $name);
            if ($guarded === null || strcasecmp($guarded->identifier->class ?? '', self::BUILDER) !== 0) { return false; }
        }
        foreach (['chunkById', 'orderedChunkById'] as $name) {
            $guarded = $codebase->getMethod(self::BUILDER, $name) ?? $codebase->getDeclaringMethod(self::BUILDER, $name);
            if ($guarded === null || strcasecmp($guarded->identifier->class ?? '', $trait) !== 0) { return false; }
        }
        return true;
    }

    private function native(Codebase $codebase, string $class, string $name, string $owner, string $suffix, string $body): bool
    {
        $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
        $node = $method === null ? null : $this->methodNode($method);
        if ($node === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0
            || $method->abstract || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || ! str_ends_with(strtolower($this->members->path($method->location->file ?? '')), strtolower('/laravel/framework/src/Illuminate'.$suffix))) { return false; }
        $expected = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$body) ?? [];
        $expected = (new NodeTraverser(new NameResolver))->traverse($expected);
        $functions = [];
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->members->path($method->location->file)) ?? [], Node\Stmt\Function_::class) as $function) {
            $functions[strtolower($function->namespacedName?->toString() ?? $function->name->name)] = true;
        }
        $actualBody = self::body($node->stmts ?? [], $codebase, $functions);
        $expectedBody = self::body($expected, $codebase);
        return $actualBody !== null && $expectedBody !== null && $actualBody === $expectedBody;
    }

    private function methodNode(FunctionLikeMetadata $method): ?Node\Stmt\ClassMethod
    {
        if ($method->location->file === null || ! $this->retain($method->location->file)) { return null; }
        $owner = $method->identifier->class === null ? null : $this->activeCodebase->getClassLike($method->identifier->class);
        if ($owner?->location->file === null || $this->members->path($owner->location->file) !== $this->members->path($method->location->file)) { return null; }
        $node = (new ModelReflection($this->activeCodebase, $this->source))->methodNode($method);
        return $node !== null && $this->methodMatches($method, $node, $method->location->file) ? $node : null;
    }

    private Codebase $activeCodebase;

    private function methodMatches(FunctionLikeMetadata $metadata, Node\Stmt\ClassMethod|Node\Stmt\Function_ $node, string $file): bool
    {
        if ($this->members->path($metadata->location->file ?? '') !== $this->members->path($file)
            || $metadata->location->span->end !== $node->getEndFilePos() + 1
            || $metadata->nameLocation?->span->start !== $node->name->getStartFilePos()
            || $metadata->nameLocation?->span->end !== $node->name->getEndFilePos() + 1
            || count($metadata->parameters) !== count($node->params) || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) !== $node->byRef) { return false; }
        $implicitAbstract = $node instanceof Node\Stmt\ClassMethod && $node->stmts === null
            && $metadata->identifier->class !== null && $this->activeCodebase->getClassLike($metadata->identifier->class)?->kind === ClassLikeKind::Interface;
        if ($node instanceof Node\Stmt\ClassMethod && ($metadata->static !== $node->isStatic() || $metadata->abstract !== ($node->isAbstract() || $implicitAbstract)
            || $metadata->visibility !== ($node->isPrivate() ? Visibility::Private : ($node->isProtected() ? Visibility::Protected : Visibility::Public)))) { return false; }
        $nativeZip = strcasecmp($metadata->identifier->name, 'zip') === 0
            && in_array(strtolower($metadata->identifier->class ?? ''), [strtolower(self::SUPPORT), strtolower(self::ENUMERABLE)], true);
        foreach ($node->params as $index => $parameter) {
            $bound = $metadata->parameters[$index];
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)
                || ltrim($bound->name, '$') !== $parameter->var->name
                || $bound->flags->contains(MetadataFlags::BY_REFERENCE) !== $parameter->byRef
                || ($bound->flags->contains(MetadataFlags::VARIADIC) !== $parameter->variadic || $nativeZip)
                    && ! $this->nativeDocumentedVariadic($metadata, $node, $bound, $parameter)) { return false; }
        }
        return true;
    }

    /** The native zip declarations document a variadic formal implemented with func_get_args(). */
    private function nativeDocumentedVariadic(FunctionLikeMetadata $method, Node $node, \Mago\Sdk\Analyzer\Metadata\ParameterMetadata $formal, Node\Param $syntax): bool
    {
        $location = $formal->type?->location;
        $comment = $node->getDocComment();
        $interface = strcasecmp($method->identifier->class ?? '', self::ENUMERABLE) === 0;
        $owner = $this->activeCodebase->getClassLike($method->identifier->class ?? '');
        $suffix = $interface ? '/laravel/framework/src/illuminate/collections/enumerable.php' : '/laravel/framework/src/illuminate/collections/collection.php';
        if (! $node instanceof Node\Stmt\ClassMethod || ! $interface && strcasecmp($method->identifier->class ?? '', self::SUPPORT) !== 0
            || strcasecmp($method->identifier->name, 'zip') !== 0 || count($node->params) !== 1 || $formal->name !== '$items'
            || $method->kind !== \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method || $method->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            || $syntax->variadic || ! $formal->flags->contains(MetadataFlags::VARIADIC) || ! $formal->type?->fromDocblock
            || $formal->outType !== null || $formal->closureThisType !== null || $formal->defaultType !== null || $syntax->default !== null
            || $owner === null || $owner->hasIncompleteHierarchy() || $location?->file === null || $comment === null
            || $owner->location->file === null || $this->members->path($owner->location->file) !== $this->members->path($location->file)
            || ! str_ends_with(strtolower($this->members->path($location->file)), $suffix)
            || $this->members->path($location->file) !== $this->members->path($method->location->file ?? '')
            || $location->span->start < $comment->getStartFilePos() || $location->span->end > $comment->getEndFilePos() + 1
            || preg_match('/@(?:phpstan|psalm)-(?:param|param-out)\b/i', $comment->getText()) === 1) { return false; }
        $before = substr($comment->getText(), 0, $location->span->start - $comment->getStartFilePos());
        $after = substr($comment->getText(), $location->span->end - $comment->getStartFilePos());
        if (preg_match('/@param\s*$/D', $before) !== 1 || preg_match('/^\s+\.\.\.\$items\b/', $after) !== 1) { return false; }
        if ($interface) {
            if ($owner->kind !== ClassLikeKind::Interface || ! $method->abstract || $node->stmts !== null
                || $method->static || $method->visibility !== Visibility::Public) { return false; }
            $counterpart = $this->activeCodebase->getMethod(self::SUPPORT, 'zip') ?? $this->activeCodebase->getDeclaringMethod(self::SUPPORT, 'zip');
            $concrete = $counterpart === null ? null : $this->methodNode($counterpart);
            $concreteType = $counterpart?->parameters[0]->type;
            if ($concrete === null || $concrete->stmts === null || count($counterpart->parameters) !== 1
                || strcasecmp($counterpart->identifier->class ?? '', self::SUPPORT) !== 0 || $concreteType?->location?->file === null
                || ! $this->nativeDocumentedVariadic($counterpart, $concrete, $counterpart->parameters[0], $concrete->params[0])) { return false; }
            $interfaceBytes = @file_get_contents($this->members->path($location->file));
            $concreteBytes = @file_get_contents($this->members->path($concreteType->location->file));
            if ($interfaceBytes === false || $concreteBytes === false
                || trim(substr($interfaceBytes, $location->span->start, $location->span->length()))
                    !== trim(substr($concreteBytes, $concreteType->location->span->start, $concreteType->location->span->length()))) { return false; }
            return $this->native($this->activeCodebase, self::SUPPORT, 'zip', self::SUPPORT, '/Collections/Collection.php',
                '$arrayableItems = array_map(fn ($items) => $this->getArrayableItems($items), func_get_args()); $params = array_merge([fn () => $this->newInstance(func_get_args()), $this->items], $arrayableItems); return $this->newInstance(array_map(...$params));');
        }
        if ($owner->kind !== ClassLikeKind::Class_ || $method->abstract || $node->stmts === null) { return false; }
        $functions = [];
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->members->path($location->file)) ?? [], Node\Stmt\Function_::class) as $function) {
            $functions[strtolower($function->namespacedName?->toString() ?? $function->name->name)] = true;
        }
        foreach ((new NodeFinder)->findInstanceOf($node->stmts ?? [], Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name || strcasecmp($call->name->toString(), 'func_get_args') !== 0
                || $call->isFirstClassCallable() || $call->args !== []) { continue; }
            $fallback = $call->name->getAttribute('namespacedName');
            $native = $this->activeCodebase->getFunction('func_get_args');
            if ($native !== null && $native->flags->contains(MetadataFlags::BUILTIN) && ! isset($functions['func_get_args'])
                && (! $fallback instanceof Node\Name || ! isset($functions[strtolower($fallback->toString())])
                    && $this->activeCodebase->getFunction($fallback->toString()) === null)) { return true; }
        }
        return false;
    }

    /** @param array<Node> $statements */
    private static function body(array $statements, ?Codebase $codebase = null, array $functions = []): ?string
    {
        $clone = unserialize(serialize($statements), ['allowed_classes' => true]);
        $normalizer = new class($codebase, $functions) extends NodeVisitorAbstract {
            public bool $valid = true;
            public function __construct(private readonly ?Codebase $codebase, private readonly array $functions) {}
            public function enterNode(Node $node): null {
                $node->setAttribute('comments', []);
                if ($node instanceof Node\Scalar\String_) { $node->setAttribute('kind', Node\Scalar\String_::KIND_SINGLE_QUOTED); }
                // Reserved constants are identical in global and namespaced source.
                if ($node instanceof Node\Expr\ConstFetch && in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true)) {
                    $node->name = new Node\Name(strtolower($node->name->toString()));
                }
                if ($node instanceof Node\Expr\FuncCall && $this->codebase !== null && $node->name instanceof Node\Name) {
                    if ($node->isFirstClassCallable()) { $this->valid = false; return null; }
                    $name = $node->name->toString();
                    $fallback = $node->name->getAttribute('namespacedName');
                    if (isset($this->functions[strtolower($name)]) || $fallback instanceof Node\Name
                        && (isset($this->functions[strtolower($fallback->toString())]) || $this->codebase->getFunction($fallback->toString()) !== null)) { $this->valid = false; return null; }
                    $function = $this->codebase->getFunction($name);
                    if ($function === null || ! $function->flags->contains(MetadataFlags::BUILTIN)) { $this->valid = false; return null; }
                    $node->name = new Node\Name\FullyQualified(strtolower($name));
                }
                return null;
            }
        };
        $clone = (new NodeTraverser($normalizer))->traverse($clone);
        return $normalizer->valid ? (new Standard)->prettyPrint($clone) : null;
    }

    /** Verify the current spelling, import scope and Type independently of metadata's old span. */
    private function documentedType(PropertyTypeProviderContext|IssueFilterContext $context, TypeMetadata $metadata): bool
    {
        $file = $metadata->location->file;
        if ($file === null || ! $this->retain($file)) { return false; }
        $bytes = @file_get_contents($this->members->path($file));
        $span = $metadata->location->span;
        if ($bytes === false || $span->start < 0 || $span->end > strlen($bytes)) { return false; }
        $expression = trim(substr($bytes, $span->start, $span->length()));
        $naming = $this->names($file, $span);
        if ($naming === null) { return false; }
        $actual = (new CastTypeExpression($context->codebase, $naming, [], []))->parse($expression)
            ?? DiagnosticArrayTypes::parse($expression);
        if ($actual === null && preg_match('/^(?:[\'"].*|[0-9]+|-?[0-9]+)$/sD', $expression) === 1) {
            try { $literal = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$expression.';')[0] ?? null; }
            catch (\PhpParser\Error) { $literal = null; }
            $value = $literal instanceof Node\Stmt\Expression ? $literal->expr : null;
            if ($value instanceof Node\Scalar\String_) { $actual = Type::literalString($value->value); }
            elseif ($value instanceof Node\Scalar\Int_) { $actual = Type::literalInt($value->value); }
            elseif ($value instanceof Node\Expr\UnaryMinus && $value->expr instanceof Node\Scalar\Int_) { $actual = Type::literalInt(-$value->expr->value); }
        }
        return $actual !== null && $context->types->equals($actual, $metadata->type);
    }

    private function magicName(TypeMetadata $metadata, string $property): bool
    {
        $file = $metadata->location->file;
        if ($file === null) { return false; }
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->members->path($file)) ?? [], Node\Stmt\ClassLike::class) as $node) {
            $comment = $node->getDocComment();
            if ($comment === null || $metadata->location->span->start < $comment->getStartFilePos()
                || $metadata->location->span->end > $comment->getEndFilePos() + 1) { continue; }
            $before = substr($comment->getText(), 0, $metadata->location->span->start - $comment->getStartFilePos());
            $after = substr($comment->getText(), $metadata->location->span->end - $comment->getStartFilePos());
            return preg_match('/@(?:phpstan-|psalm-)?property(?:-read)?\s*$/D', $before) === 1
                && preg_match('/^\s+\$'.preg_quote($property, '/').'\b/', $after) === 1;
        }
        return false;
    }

    private function physical(PropertyMetadata $metadata): bool
    {
        $name = $metadata->nameLocation;
        if ($name?->file === null || ! $this->retain($name->file)) { return false; }
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->members->path($name->file)) ?? [], Node\Stmt\Property::class) as $declaration) {
            foreach ($declaration->props as $property) {
                if ($property->name->getStartFilePos() !== $name->span->start || $property->name->getEndFilePos() + 1 !== $name->span->end) { continue; }
                return ltrim($metadata->name, '$') === $property->name->name && $declaration->isPublic() && ! $declaration->isStatic()
                    && ($declaration->hooks ?? []) === [];
            }
        }
        return false;
    }

    private function names(string $file, Span $span): ?NameContext
    {
        $contents = @file_get_contents($this->members->path($file));
        if ($contents === false) { return null; }
        try { $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []; }
        catch (\PhpParser\Error) { return null; }
        $resolver = new NameResolver;
        $collector = new class($resolver, $span) extends NodeVisitorAbstract {
            public ?NameContext $context = null;
            public function __construct(private readonly NameResolver $resolver, private readonly Span $span) {}
            public function enterNode(Node $node): null {
                if (($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_)
                    && ($node->getDocComment()?->getStartFilePos() ?? $node->getStartFilePos()) <= $this->span->start
                    && $node->getEndFilePos() + 1 >= $this->span->end) { $this->context = clone $this->resolver->getNameContext(); }
                return null;
            }
        };
        try { (new NodeTraverser($resolver, $collector))->traverse($nodes); }
        catch (\PhpParser\Error) { return null; }
        return $collector->context;
    }

    private function retain(string $file): bool
    {
        $file = $this->members->path($file);
        if (! $this->members->declarationCurrent($file)) { return false; }
        if (isset($this->snapshots[$file])) { return $this->snapshots[$file] === @hash_file('sha256', $file); }
        if ($this->source->read($file) === null || ($hash = $this->source->contentHash($file)) === null) { return false; }
        $this->snapshots[$file] = $hash;
        return true;
    }

    private function current(): bool
    {
        foreach ($this->snapshots as $file => $hash) { if ($hash !== @hash_file('sha256', $file)) { return false; } }
        return true;
    }
}
