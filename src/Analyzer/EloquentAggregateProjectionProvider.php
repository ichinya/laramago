<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\AggregateProjectionCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\SchemaIndex;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ObjectProperty;
use Mago\Sdk\Analyzer\Type\ObjectShapeType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/** Aggregate aliases on a complete immutable-in-syntax Eloquent query chain. */
final class EloquentAggregateProjectionProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';
    private const COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';
    private ?PhpSource $source = null;
    private ?SchemaIndex $schema = null;

    public function __construct(
        private readonly string $root,
        public readonly AggregateProjectionCalls $calls = new AggregateProjectionCalls,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->schema = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::BUILDER, 'get')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $receiver = $context->invocation->receiverType?->atomicTypes[0] ?? null;
        $model = $receiver instanceof NamedObjectType ? ($receiver->parameters[0] ?? null) : null;
        $atom = $model?->atomicTypes[0] ?? null;
        $call = $this->calls->call($context->invocation->span);
        if (
            ! $receiver instanceof NamedObjectType
            || $context->invocation->kind !== InvocationKind::InstanceMethod
            || strcasecmp($context->invocation->name, 'get') !== 0
            || strcasecmp($context->invocation->declaringClass ?? '', self::BUILDER) !== 0
            || $receiver->name !== self::BUILDER
            || count($context->invocation->receiverType?->atomicTypes ?? []) !== 1
            || ! $atom instanceof NamedObjectType
            || count($model?->atomicTypes ?? []) !== 1
            || $context->invocation->arguments !== []
            || $call === null
            || $call->args !== []
        ) {
            return null;
        }
        $chain = [];
        $cursor = $call;
        while ($cursor instanceof Node\Expr\MethodCall) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->var;
        }
        if (
            ! $cursor instanceof Node\Expr\StaticCall
            || ! $cursor->class instanceof Node\Name\FullyQualified
            || strcasecmp($cursor->class->toString(), $atom->name) !== 0
        ) {
            return null;
        }
        array_unshift($chain, $cursor);
        $source = $this->source ??= new PhpSource($this->root);
        $reflection = new ModelReflection($context->codebase, $source);
        if (! $this->standardModel($context, $reflection, $atom->name)) {
            return null;
        }
        if (! $this->nativeBody($reflection, self::QUERY, 'selectRaw', <<<'PHP'
$this->addSelect(new \Illuminate\Database\Query\Expression($expression));
if ($bindings) {
    $this->addBinding($bindings, 'select');
}
return $this;
PHP) || ! $this->nativeBody($reflection, self::BUILDER, 'get', <<<'PHP'
$builder = $this->applyScopes();
if (count($models = $builder->getModels($columns)) > 0) {
    $models = $builder->eagerLoadRelations($models);
}
return $this->applyAfterQueryCallbacks($builder->getModel()->newCollection($models));
PHP)) {
            return null;
        }
        $terminal = $reflection->method(self::BUILDER, 'get');
        $terminalType = $terminal?->returnType?->type;
        $terminalCollection = $terminalType?->atomicTypes[0] ?? null;
        $terminalModel = $terminalCollection instanceof NamedObjectType ? ($terminalCollection->parameters[1] ?? null) : null;
        $template = $terminalModel?->atomicTypes[0] ?? null;
        if ($terminal?->declaredReturnType !== null
            || count($terminalType?->atomicTypes ?? []) !== 1
            || ! $terminalCollection instanceof NamedObjectType
            || $terminalCollection->name !== self::COLLECTION
            || count($terminalCollection->parameters ?? []) !== 2
            || ! $context->types->equals($terminalCollection->parameters[0], Type::int())
            || count($terminalModel?->atomicTypes ?? []) !== 1
            || ! $template instanceof GenericParameterType || $template->name !== 'TModel') {
            return null;
        }
        $table = $reflection->table($atom->name);
        if ($table === null) {
            return null;
        }
        if ($this->schema === null) {
            $this->schema = new SchemaIndex($source);
            $this->schema->load();
        }
        $sql = null;
        foreach (array_slice($chain, 0, -1) as $index => $step) {
            if (! $step->name instanceof Node\Identifier || $step->isFirstClassCallable()) {
                return null;
            }
            foreach ($step->args as $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->name !== null) {
                    return null;
                }
            }
            $name = strtolower($step->name->name);
            $dispatch = new EloquentModelDispatch;
            if (! $dispatch->supportsModel($context->codebase, $atom->name, $name)
                || $reflection->customMethod($atom->name, $name) !== null
                || $reflection->customMethod($atom->name, 'scope'.ucfirst($name)) !== null) {
                return null;
            }
            if ($name === 'query' && $index === 0 && $step->args === []) {
                continue;
            }
            $owner = $name === 'where' ? self::BUILDER : self::QUERY;
            $native = $reflection->method($owner, $name);
            $eloquentDeclaration = $reflection->method(self::BUILDER, $name);
            if ($native === null || strcasecmp($native->identifier->class ?? '', $owner) !== 0
                || $native->static || $native->visibility !== Visibility::Public
                || ($owner === self::QUERY && $eloquentDeclaration !== null
                    && strcasecmp($eloquentDeclaration->identifier->class ?? '', self::QUERY) !== 0)
                || ! str_ends_with('/'.ltrim(str_replace('\\', '/', $native->location->file ?? ''), '/'),
                    '/laravel/framework/src/'.str_replace('\\', '/', $owner).'.php')) {
                return null;
            }
            if ($name === 'selectraw' && $sql === null && count($step->args) === 1
                && $step->args[0]->value instanceof Node\Scalar\String_) {
                $sql = $step->args[0]->value->value;
                continue;
            }
            if ($name === 'where' && count($step->args) >= 2 && count($step->args) <= 3
                && $step->args[0]->value instanceof Node\Scalar\String_
                && $this->column($table, $step->args[0]->value->value) !== null) {
                if (count($step->args) === 3 && ! $step->args[1]->value instanceof Node\Scalar\String_) {
                    return null;
                }
                // A closure as the second argument can mutate the query through a nested subquery.
                if ($step->args[1]->value instanceof Node\Expr\Closure
                    || $step->args[1]->value instanceof Node\Expr\ArrowFunction) {
                    return null;
                }
                continue;
            }
            if ($name === 'groupby' && count($step->args) === 1
                && $step->args[0]->value instanceof Node\Scalar\String_
                && $this->column($table, $step->args[0]->value->value) !== null) {
                continue;
            }
            return null;
        }
        $casts = $reflection->casts($atom->name);
        if ($sql === null || ! is_array($casts)) {
            return null;
        }
        $properties = [];
        foreach (explode(',', $sql) as $expression) {
            $expression = trim($expression);
            if ($this->column($table, $expression) !== null) {
                continue;
            }
            if (preg_match('/^(COUNT|SUM)\(\s*(\*|[A-Za-z_][A-Za-z0-9_.]*)\s*\)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/iD', $expression, $parts) !== 1) {
                return null;
            }
            [, $aggregate, $field, $alias] = $parts;
            if ($alias !== strtolower($alias)) {
                return null;
            }
            $studly = ModelReflection::studly($alias);
            if ($this->column($table, $alias) !== null || array_key_exists($alias, $casts)
                || isset($properties[$alias]) || $reflection->automaticDate($atom->name, $alias)
                || $reflection->method($atom->name, 'get'.$studly.'Attribute') !== null
                || $reflection->method($atom->name, 'set'.$studly.'Attribute') !== null
                || $reflection->method($atom->name, lcfirst($studly)) !== null
                || $context->codebase->getDeclaringProperty($atom->name, '$'.$alias) !== null
                || $context->codebase->getDeclaringMagicProperty($atom->name, '$'.$alias) !== null) {
                return null;
            }
            $numeric = Type::union(Type::int(), Type::float(), self::numericString());
            if (strcasecmp($aggregate, 'COUNT') === 0 && $field === '*') {
                $type = Type::union(Type::int(), self::numericString());
            } elseif (strcasecmp($aggregate, 'SUM') === 0
                && ($column = $this->column($table, $field)) !== null
                && in_array($this->schema->column($table, $column)?->type, ['int', 'float', 'decimal'], true)) {
                $type = Type::union($numeric, Type::null());
            } else {
                return null;
            }
            $properties[$alias] = new ObjectProperty($alias, false, $type);
        }
        if ($properties === []) {
            return null;
        }
        $projected = Type::fromAtomic(new NamedObjectType(
            $atom->name, $atom->parameters, $atom->variances, false, false,
            [new ObjectShapeType(array_values($properties), false)], $atom->remappedParameters,
        ));
        $collection = (new EloquentCollectionType)->resolve($context->codebase, $projected);
        $collectionAtom = $collection?->atomicTypes[0] ?? null;

        return $collectionAtom instanceof NamedObjectType && $collectionAtom->name === self::COLLECTION
            ? $collection : null;
    }

    private function standardModel(ReturnTypeProviderContext $context, ModelReflection $reflection, string $model): bool
    {
        if (! (new EloquentModelDispatch)->supportsModel($context->codebase, $model, 'get')) {
            return false;
        }
        foreach (['newBaseQueryBuilder', 'newInstance', 'newFromBuilder', 'setRawAttributes', '__get',
            'getAttribute', 'getAttributeValue', 'getAttributeFromArray', 'transformModelValue',
            'hasAttribute', 'hasGetMutator', 'hasAttributeMutator', 'hasAttributeGetMutator',
            'mergeAttributeFromCachedCasts', 'getAttributes', 'mergeAttributesFromCachedCasts',
            'mergeAttributesFromClassCasts', 'mergeAttributesFromAttributeCasts', 'syncOriginal',
            'castAttribute', 'getDates', 'hasCast', 'hasAnyGetMutator', 'fireModelEvent', 'setConnection',
            'getGlobalScopes', 'resolveGlobalScopeAttributes', 'addGlobalScopes', 'addGlobalScope',
            'boot', 'booting', 'initializeTraits', 'registerGlobalScopes'] as $method) {
            if ($reflection->customMethod($model, $method) !== null) {
                return false;
            }
        }
        if (! $this->writeOnlyBooted($reflection, $model)) {
            return false;
        }
        $events = $reflection->default($model, 'dispatchesEvents', []);
        if (! is_array($events) || array_key_exists('retrieved', $events)) {
            return false;
        }
        foreach ($context->codebase->getMultipleClassLikes([$model, ...$context->codebase->getClassAncestors($model)]) as $class) {
            if ($class === null) {
                return false;
            }
            foreach ($class->attributes as $attribute) {
                if (str_ends_with($attribute->name, '\\ScopedBy') || str_ends_with($attribute->name, '\\ObservedBy')) {
                    return false;
                }
            }
            foreach ($class->methods as $method) {
                if (strtolower($method) !== 'booted'
                    && (str_starts_with(strtolower($method), 'boot') || str_starts_with(strtolower($method), 'initialize'))
                    && $reflection->customMethod($model, $method) !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Write-event registration cannot change the projection when models are hydrated. */
    private function writeOnlyBooted(ModelReflection $reflection, string $model): bool
    {
        $boot = $reflection->customMethod($model, 'booted');
        if ($boot === null) {
            return true;
        }
        $node = $reflection->methodNode($boot);
        if ($node === null || ! $boot->static) {
            return false;
        }
        foreach ($node->stmts ?? [] as $statement) {
            $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (! $call instanceof Node\Expr\StaticCall || ! $call->class instanceof Node\Name
                || ! in_array(strtolower($call->class->toString()), ['static', 'self'], true)
                || ! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()
                || ! in_array(strtolower($call->name->name), ['creating', 'created', 'updating', 'updated',
                    'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored'], true)
                || count($call->args) !== 1 || ! $call->args[0] instanceof Node\Arg
                || $call->args[0]->unpack || $call->args[0]->name !== null
                || ! $call->args[0]->value instanceof Node\Expr\Closure
                || $reflection->customMethod($model, $call->name->name) !== null
                || $reflection->customMethod($model, 'registerModelEvent') !== null) {
                return false;
            }
            $event = $reflection->method($model, $call->name->name);
            $eventNode = $event === null ? null : $reflection->methodNode($event);
            if ($eventNode === null || ! $event->static
                || self::tokens((new Standard)->prettyPrint($eventNode->stmts ?? []))
                    !== self::tokens("static::registerModelEvent('".strtolower($call->name->name)."', $"."callback);")) {
                return false;
            }
        }

        return true;
    }

    private function nativeBody(ModelReflection $reflection, string $class, string $name, string $body): bool
    {
        $method = $reflection->method($class, $name);
        $node = $method === null ? null : $reflection->methodNode($method);
        if ($method === null || $node === null || $method->static || $method->visibility !== Visibility::Public
            || strcasecmp($method->identifier->class ?? '', $class) !== 0
            || ! str_ends_with('/'.ltrim(str_replace('\\', '/', $method->location->file ?? ''), '/'), '/laravel/framework/src/'.str_replace('\\', '/', $class).'.php')) {
            return false;
        }
        return self::tokens((new Standard)->prettyPrint($node->stmts ?? [])) === self::tokens($body);
    }

    private static function tokens(string $source): string
    {
        $result = '';
        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_string($token)) {
                $result .= $token;
            } elseif (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $result .= $token[1];
            }
        }

        return $result;
    }

    private function column(string $table, string $name): ?string
    {
        if (str_starts_with($name, $table.'.')) {
            $name = substr($name, strlen($table) + 1);
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1
            && $this->schema?->column($table, $name) !== null ? $name : null;
    }

    private static function numericString(): Type
    {
        return Type::fromAtomic(new ScalarType(ScalarTypeKind::String, new StringType(
            StringLiteralKind::General, null, true, false, true, false, StringCasing::Unspecified,
        )));
    }
}
