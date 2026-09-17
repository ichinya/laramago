<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;

/** Resolve native relationship method generics using source and SDK metadata only. */
final class RelationMethodInference
{
    private const RELATIONS = [
        'hasmany' => 'HasMany',
        'hasone' => 'HasOne',
        'belongsto' => 'BelongsTo',
        'belongstomany' => 'BelongsToMany',
        'hasmanythrough' => 'HasManyThrough',
        'hasonethrough' => 'HasOneThrough',
        'morphone' => 'MorphOne',
        'morphmany' => 'MorphMany',
        'morphtomany' => 'MorphToMany',
        'morphedbymany' => 'MorphToMany',
    ];
    private const CONCERN = 'Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships';

    public function __construct(
        private readonly Codebase $codebase,
        private readonly PhpSource $source,
    ) {}

    public function infer(string $owner, string $name): ?Type
    {
        if (! $this->isModel($this->codebase, $owner)) {
            return null;
        }
        $reflection = new ModelReflection($this->codebase, $this->source);
        $method = $reflection->customMethod($owner, $name);
        if (
            $method === null
            || $method->static
            || $method->parameters !== []
            || $method->templates !== []
            || $method->returnType !== null
            && $method->returnType->fromDocblock
        ) {
            return null;
        }
        $declared = $method->declaredReturnType?->type;
        $relation = $declared?->atomicTypes[0] ?? null;
        if ($declared === null || count($declared->atomicTypes) !== 1 || ! $relation instanceof NamedObjectType) {
            return null;
        }
        if (! in_array(
            $relation->name,
            array_map(
                static fn (string $kind): string => 'Illuminate\\Database\\Eloquent\\Relations\\'.$kind,
                self::RELATIONS,
            ),
            true,
        )) {
            return null;
        }
        $declaring = $this->declaringContext($owner, $method->identifier->class ?? '');
        if ($declaring === null) {
            return null;
        }
        $expression = $reflection->returnExpression($method);
        if (! $expression instanceof Node\Expr\MethodCall) {
            return null;
        }
        $modifiers = [];
        while ($expression->var instanceof Node\Expr\MethodCall) {
            $modifiers[] = $expression;
            $expression = $expression->var;
        }
        if (
            ! $expression->var instanceof Node\Expr\Variable
            || $expression->var->name !== 'this'
            || ! $expression->name instanceof Node\Identifier
        ) {
            return null;
        }
        $factory = strtolower($expression->name->toString());
        $kind = self::RELATIONS[$factory] ?? null;
        if ($kind === null || $relation->name !== 'Illuminate\\Database\\Eloquent\\Relations\\'.$kind) {
            return null;
        }
        if (! $this->standardFactory($this->codebase, $reflection, $owner, $name, $factory)) {
            return null;
        }
        $arguments = $this->factoryArguments($expression, $factory);
        if ($arguments === null || ! $this->nativeModifiers($reflection, $relation->name, $modifiers)) {
            return null;
        }
        $related = PhpSource::value($arguments['related'], $declaring, $owner);
        if (! is_string($related) || ! $this->isModel($this->codebase, $related)) {
            return null;
        }
        $parameters = [Type::namedObject($related), Type::namedObject($owner)];
        $templates = ['TRelatedModel', 'TDeclaringModel'];
        if (isset($arguments['through'])) {
            $through = PhpSource::value($arguments['through'], $declaring, $owner);
            if (! is_string($through) || ! $this->isModel($this->codebase, $through)) {
                return null;
            }
            array_splice($parameters, 1, 0, [Type::namedObject($through)]);
            array_splice($templates, 1, 0, ['TIntermediateModel']);
        }
        $metadata = $this->codebase->getClass($relation->name);
        if ($metadata === null) {
            return null;
        }
        $installed = array_map(static fn ($template): string => $template->name, $metadata->templates);
        if (in_array($kind, ['BelongsToMany', 'MorphToMany'], true) && count($installed) === 4) {
            $templates = [...$templates, 'TPivotModel', 'TAccessor'];
            $pivot = $kind === 'MorphToMany' ? 'MorphPivot' : 'Pivot';
            $pivotDefault = $metadata->templates[2]->default;
            $pivotType = $pivotDefault?->atomicTypes[0] ?? null;
            if (
                $pivotDefault === null
                || count($pivotDefault->atomicTypes) !== 1
                || ! $pivotType instanceof NamedObjectType
                || $pivotType->name !== 'Illuminate\\Database\\Eloquent\\Relations\\'.$pivot
                || $metadata->templates[3]->default?->getLiteralString() !== 'pivot'
            ) {
                return null;
            }
            $pivotContracts = $this->pivotContracts($modifiers, $declaring, $owner, $pivot);
            if ($pivotContracts === null) {
                return null;
            }
            $parameters[] = Type::namedObject($pivotContracts[0]);
            $parameters[] = Type::literalString($pivotContracts[1]);
        }
        foreach ($modifiers as $modifier) {
            if (
                count($installed) !== 4
                && $modifier->name instanceof Node\Identifier
                && in_array(strtolower($modifier->name->toString()), ['using', 'as'], true)
            ) {
                return null;
            }
        }
        if ($installed !== $templates) {
            return null;
        }

        return Type::namedObject($relation->name, ...$parameters);
    }

    /** Resolve trait self::class to its lexical consuming class, not a later child. */
    private function declaringContext(string $owner, string $declaring): ?string
    {
        $metadata = $this->codebase->getClassLike($declaring);
        if ($metadata === null || $metadata->kind !== ClassLikeKind::Trait) {
            return $declaring;
        }
        if ($metadata->templates !== []) {
            return null;
        }
        $class = $this->codebase->getClass($owner);
        while ($class !== null) {
            $file = $class->location->file;
            foreach ((new \PhpParser\NodeFinder)->findInstanceOf(
                $file === null ? [] : $this->source->read($file) ?? [],
                Node\Stmt\Class_::class,
            ) as $node) {
                if ($node->getStartFilePos() !== $class->location->span->start) {
                    continue;
                }
                foreach ($node->stmts as $statement) {
                    if (! $statement instanceof Node\Stmt\TraitUse) {
                        continue;
                    }
                    foreach ($statement->traits as $trait) {
                        if ($this->usesTrait($trait->toString(), $declaring, [])) {
                            return $class->originalName;
                        }
                    }
                }
            }
            $class = $class->directParentClass === null ? null : $this->codebase->getClass($class->directParentClass);
        }

        return null;
    }

    /** @param list<string> $seen */
    private function usesTrait(string $trait, string $target, array $seen): bool
    {
        if (strcasecmp($trait, $target) === 0) {
            return true;
        }
        if (in_array(strtolower($trait), $seen, true)) {
            return false;
        }
        $seen[] = strtolower($trait);
        foreach ($this->codebase->getTrait($trait)->usedTraits ?? [] as $nested) {
            if ($this->usesTrait($nested, $target, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Node\Expr\MethodCall> $calls
     * @return array{string, string}|null
     */
    private function pivotContracts(array $calls, string $declaring, string $owner, string $default): ?array
    {
        $pivot = 'Illuminate\\Database\\Eloquent\\Relations\\'.$default;
        $accessor = 'pivot';
        // The outermost modifier is last at runtime and must win.
        foreach (array_reverse($calls) as $call) {
            $name = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '';
            if (! in_array($name, ['using', 'as'], true)) {
                continue;
            }
            $argument = $call->args[0] ?? null;
            if (! $argument instanceof Node\Arg) {
                return null;
            }
            $value = PhpSource::value($argument->value, $declaring, $owner);
            if (! is_string($value)) {
                return null;
            }
            if ($name === 'as') {
                $accessor = $value;
                continue;
            }
            $base = 'Illuminate\\Database\\Eloquent\\Relations\\'.$default;
            if (
                ! $this->isModel($this->codebase, $value)
                || strcasecmp($value, $base) !== 0
                && ! in_array(
                    strtolower($base),
                    array_map(strtolower(...), $this->codebase->getClassAncestors($value)),
                    true,
                )
            ) {
                return null;
            }
            $pivot = $value;
        }

        return [$pivot, $accessor];
    }

    private function standardFactory(
        Codebase $codebase,
        ModelReflection $reflection,
        string $owner,
        string $method,
        string $factory,
    ): bool {
        $kind = self::RELATIONS[$factory];
        $factories = [$factory, 'new'.$kind, 'newRelatedInstance'];
        if (str_ends_with($factory, 'through')) {
            $factories[] = 'newRelatedThroughInstance';
        }
        if ($factory === 'morphedbymany') {
            $factories[] = 'morphToMany';
        }
        if ($factory === 'belongstomany') {
            $factories[] = 'joiningTable';
        }
        $contracts = array_map(strtolower(...), [$method, ...$factories]);
        foreach ($codebase->getMultipleClasses([$owner, ...$codebase->getClassAncestors($owner)]) as $class) {
            if (($class->templates ?? []) !== []) {
                return false;
            }
            foreach ([...($class->pseudoMethods ?? []), ...($class->staticPseudoMethods ?? [])] as $documented) {
                if (in_array(strtolower($documented), $contracts, true)) {
                    return false;
                }
            }
        }
        foreach ($factories as $name) {
            $native = $reflection->method($owner, $name);
            if (
                $native === null
                || ! in_array(
                    strtolower($native->identifier->class ?? ''),
                    [
                        strtolower(ModelReflection::MODEL),
                        strtolower(self::CONCERN),
                    ],
                    true,
                )
            ) {
                return false;
            }
        }
        $factoryMethod = $reflection->method($owner, $factory);
        $return = $factoryMethod?->returnType?->type;
        $relation = $return?->atomicTypes[0] ?? null;

        return (
            $return !== null
            && count($return->atomicTypes) === 1
            && $relation instanceof NamedObjectType
            && $relation->name === 'Illuminate\\Database\\Eloquent\\Relations\\'.$kind
            && array_map(static fn ($parameter): string => ltrim(
                $parameter->name,
                '$',
            ), $factoryMethod->parameters) === $this->argumentNames($factory)
        );
    }

    /** @return array<string, Node\Expr>|null */
    private function factoryArguments(Node\Expr\MethodCall $expression, string $factory): ?array
    {
        $names = $this->argumentNames($factory);
        $seen = [];
        $arguments = [];
        $named = false;
        foreach ($expression->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            if ($named && $argument->name === null) {
                return null;
            }
            $named = $argument->name !== null;
            $name = $argument->name?->toString() ?? $names[$index] ?? null;
            if ($name === null || ! in_array($name, $names, true) || isset($seen[$name])) {
                return null;
            }
            $seen[$name] = true;
            $arguments[$name] = $argument->value;
            if (in_array($name, ['related', 'through'], true)) {
                if (
                    ! $argument->value instanceof Node\Expr\ClassConstFetch
                    || ! $argument->value->class instanceof Node\Name
                    || ! $argument->value->name instanceof Node\Identifier
                    || strtolower($argument->value->name->toString()) !== 'class'
                ) {
                    return null;
                }
                continue;
            }
            $value = PhpSource::value($argument->value);
            if ($name === 'inverse' ? ! is_bool($value) : $value !== null && ! is_string($value)) {
                return null;
            }
            if ($name === 'name' && (! is_string($value) || $value === '')) {
                return null;
            }
            // A pivot class passed as the table changes the pivot generic.
            // Custom pivots and dynamically computed table names need an explicit contract.
            if (
                $name === 'table'
                && is_string($value)
                && ($argument->value instanceof Node\Expr\ClassConstFetch
                || str_contains($value, '\\')
                || $this->codebase->classExists($value))
            ) {
                return null;
            }
        }

        $required = ['related'];
        if (str_ends_with($factory, 'through')) {
            $required[] = 'through';
        }
        if (str_starts_with($factory, 'morph')) {
            $required[] = 'name';
        }
        foreach ($required as $name) {
            if (! isset($arguments[$name])) {
                return null;
            }
        }

        return $arguments;
    }

    /** @return list<string> */
    private function argumentNames(string $factory): array
    {
        return match ($factory) {
            'belongsto' => ['related', 'foreignKey', 'ownerKey', 'relation'],
            'belongstomany' => [
                'related',
                'table',
                'foreignPivotKey',
                'relatedPivotKey',
                'parentKey',
                'relatedKey',
                'relation',
            ],
            'hasmanythrough', 'hasonethrough' => [
                'related',
                'through',
                'firstKey',
                'secondKey',
                'localKey',
                'secondLocalKey',
            ],
            'morphone', 'morphmany' => ['related', 'name', 'type', 'id', 'localKey'],
            'morphtomany' => [
                'related',
                'name',
                'table',
                'foreignPivotKey',
                'relatedPivotKey',
                'parentKey',
                'relatedKey',
                'relation',
                'inverse',
            ],
            'morphedbymany' => [
                'related',
                'name',
                'table',
                'foreignPivotKey',
                'relatedPivotKey',
                'parentKey',
                'relatedKey',
                'relation',
            ],
            default => ['related', 'foreignKey', 'localKey'],
        };
    }

    private function isModel(Codebase $codebase, string $class): bool
    {
        $metadata = $codebase->getClass($class);
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $ancestor) {
            if (($ancestor->templates ?? []) !== []) {
                return false;
            }
        }

        return (
            $metadata !== null
            && $metadata->templates === []
            && in_array(
                strtolower(ModelReflection::MODEL),
                array_map(strtolower(...), $codebase->getClassAncestors($class)),
                true,
            )
        );
    }

    /** @param list<Node\Expr\MethodCall> $calls */
    private function nativeModifiers(ModelReflection $reflection, string $relation, array $calls): bool
    {
        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                return false;
            }
            $name = strtolower($call->name->toString());
            $contract = match ($name) {
                'withtimestamps' => ['BelongsToMany', ['createdAt', 'updatedAt']],
                'using' => ['BelongsToMany', ['class']],
                'as' => ['BelongsToMany', ['accessor']],
                'withtrashedparents' => ['HasOneOrManyThrough', []],
                default => null,
            };
            if ($contract === null) {
                return false;
            }
            $method = $reflection->method($relation, $name);
            $return = $method?->returnType?->type;
            $returned = $return?->atomicTypes[0] ?? null;
            if (
                $method === null
                || $method->identifier->class !== 'Illuminate\\Database\\Eloquent\\Relations\\'.$contract[0]
                || array_map(static fn ($parameter): string => ltrim($parameter->name, '$'), $method->parameters)
                    !== $contract[1]
                || $return === null
                || count($return->atomicTypes) !== 1
                || ! $returned instanceof NamedObjectType
                || ! $returned->isThis
            ) {
                return false;
            }
            if (in_array($name, ['using', 'as'], true) && count($call->args) !== 1) {
                return false;
            }
            $seen = [];
            $named = false;
            foreach ($call->args as $index => $argument) {
                if (
                    ! $argument instanceof Node\Arg
                    || $argument->unpack
                    || $argument->byRef
                    || $named
                    && $argument->name === null
                ) {
                    return false;
                }
                $named = $argument->name !== null;
                $argumentName = $argument->name?->toString() ?? $contract[1][$index] ?? null;
                if (
                    $argumentName === null
                    || ! in_array($argumentName, $contract[1], true)
                    || isset($seen[$argumentName])
                ) {
                    return false;
                }
                $seen[$argumentName] = true;
                if ($name === 'using') {
                    if (
                        ! $argument->value instanceof Node\Expr\ClassConstFetch
                        || ! $argument->value->class instanceof Node\Name
                        || ! $argument->value->name instanceof Node\Identifier
                        || strtolower($argument->value->name->toString()) !== 'class'
                    ) {
                        return false;
                    }
                    continue;
                }
                $value = PhpSource::value($argument->value);
                if ($name === 'as' && (! is_string($value) || $value === '')) {
                    return false;
                }
                if ($value !== null && ! is_string($value)) {
                    return false;
                }
            }
        }

        return true;
    }
}
