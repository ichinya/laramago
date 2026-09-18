<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Validate literal relation paths without evaluating application methods. */
final class EloquentRelationNamesHook implements MethodCallAnalysisHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const RELATION = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';
    private const EAGER_LOAD_METHODS = ['with', 'load', 'loadmissing', 'withwherehas'];
    private const RELATION_METHODS = [
        'has',
        'orhas',
        'doesnthave',
        'ordoesnthave',
        'wherehas',
        'orwherehas',
        'wheredoesnthave',
        'orwheredoesnthave',
        'withwherehas',
    ];

    private readonly RelationNameContracts $contracts;

    private ?string $sourceHash = null;

    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(string $projectRoot = '.')
    {
        $this->contracts = new RelationNameContracts($projectRoot);
    }

    public function getTargets(): array
    {
        return [
            ...array_map(
                static fn (string $method): MethodTarget => MethodTarget::exact(self::BUILDER, $method),
                ['with', ...self::RELATION_METHODS],
            ),
            ...array_map(
                static fn (string $method): MethodTarget => MethodTarget::exact(self::MODEL, $method),
                ['load', 'loadmissing', 'with', ...self::RELATION_METHODS],
            ),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if ($context->codebase->getClass(self::RELATION) === null) {
            return;
        }
        $sourceHash = hash('sha256', $context->source->contents);
        if ($sourceHash !== $this->sourceHash) {
            $this->sourceHash = $sourceHash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return;
            }
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $node) {
                if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                    $this->calls[$node->getStartFilePos().':'.($node->getEndFilePos() + 1)] = $node;
                }
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            && ! $call instanceof Node\Expr\StaticCall
            || ! $call->name instanceof Node\Identifier
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $methodName = strtolower($call->name->name);
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name\FullyQualified
                || in_array($methodName, ['load', 'loadmissing'], true)
            ) {
                return;
            }
            $model = $call->class->toString();
            foreach (['newEloquentBuilder', 'newQuery', 'newModelQuery', 'newQueryWithoutScopes'] as $factory) {
                $declaring = $context->codebase->getDeclaringMethod($model, $factory)?->identifier->class;
                if ($declaring !== null && $declaring !== self::MODEL) {
                    return;
                }
            }
            $owner = $context->codebase->getDeclaringMethod($model, $methodName)?->identifier->class;
            if (
                $owner === null
                && in_array($methodName, self::RELATION_METHODS, true)
                && $context->codebase->getDeclaringMethod($model, '__callStatic')?->identifier->class === self::MODEL
            ) {
                $owner = $context->codebase->getDeclaringMethod(self::BUILDER, $methodName)?->identifier->class;
            }
        } else {
            $receiver = $this->object($context->receiverType);
            if ($receiver === null) {
                return;
            }
            $model = $receiver->name === self::BUILDER
                ? $this->object($receiver->parameters[0] ?? null)?->name
                : $receiver->name;
            $owner = $context->codebase->getDeclaringMethod($receiver->name, $methodName)?->identifier->class;
        }
        if (
            $model === null
            || $model === self::MODEL
            || ! $this->isA($context->codebase, $model, self::MODEL)
            || ! $this->hasStandardDispatch($context->codebase, $model)
        ) {
            return;
        }
        if (! in_array(
            $owner,
            [self::MODEL, self::BUILDER, 'Illuminate\\Database\\Eloquent\\Concerns\\QueriesRelationships'],
            true,
        )) {
            return;
        }
        $argument = null;
        $parameter = in_array($methodName, self::RELATION_METHODS, true) ? 'relation' : 'relations';
        foreach ($call->getArgs() as $offset => $arg) {
            if ($arg->unpack) {
                return;
            }
            if (
                $arg->name === null
                && $offset === 0
                || $arg->name !== null
                && $arg->name->name === $parameter
            ) {
                $argument = $arg->value;
            }
        }
        if ($argument === null) {
            return;
        }
        foreach ($this->paths($argument) as $path) {
            $current = $model;
            $relationPath = in_array($methodName, self::EAGER_LOAD_METHODS, true)
                ? explode(':', $path, 2)[0]
                : $path;
            foreach (explode('.', $relationPath) as $segment) {
                if ($segment === '' || $segment === '*') {
                    break;
                }
                if (
                    ! $this->hasStandardDispatch($context->codebase, $current)
                    || $this->contracts->isDynamic($current, $segment)
                ) {
                    break;
                }
                $method = $context->codebase->getDeclaringMethod($current, $segment);
                // An absent declaration may be registered through resolveRelationUsing().
                // Do not turn incomplete static information into an error.
                if ($method === null) {
                    $class = $context->codebase->getClass($current);
                    if (
                        $this->contracts->isComplete($current)
                        && $class !== null
                        && ! $class->hasIncompleteHierarchy()
                    ) {
                        $context->report(
                            Level::Warning,
                            'laramago-missing-relation',
                            Issue::at(
                                'Relation '
                                .$current
                                .'::'
                                .$segment
                                .' is absent from its complete relation contract (path '
                                .$path
                                .').',
                                new SourceLocation($context->source->path, $context->node->span),
                            ),
                        );
                    }
                    break;
                }
                $return = $this->object($method->returnType?->type ?? $method->declaredReturnType?->type);
                if ($return === null) {
                    break;
                }
                $returnClass = $context->codebase->getClass($return->name);
                if ($returnClass === null || $returnClass->hasIncompleteHierarchy()) {
                    break;
                }
                if (! $this->isA($context->codebase, $return->name, self::RELATION)) {
                    $context->report(
                        Level::Warning,
                        'laramago-invalid-relation',
                        Issue::at(
                            'Method '
                            .$current
                            .'::'
                            .$segment
                            .'() does not return an Eloquent relation (path '
                            .$path
                            .').',
                            new SourceLocation($context->source->path, $context->node->span),
                        ),
                    );
                    break;
                }
                $related = $this->object($return->parameters[0] ?? null);
                if ($related === null || ! $this->isA($context->codebase, $related->name, self::MODEL)) {
                    break;
                }
                $current = $related->name;
            }
        }
    }

    private function object(?Type $type): ?NamedObjectType
    {
        return $type !== null && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof NamedObjectType
            ? $type->atomicTypes[0]
            : null;
    }

    private function hasStandardDispatch(Codebase $codebase, string $model): bool
    {
        foreach (['__call', 'relationResolver'] as $name) {
            $method = $codebase->getDeclaringMethod($model, $name);
            if (
                $method !== null
                && ! in_array(
                    $method->identifier->class,
                    [self::MODEL, 'Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships'],
                    true,
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function isA(Codebase $codebase, string $class, string $parent): bool
    {
        return (
            strcasecmp($class, $parent) === 0
            || in_array(
                strtolower($parent),
                array_map(strtolower(...), $codebase->getClass($class)?->parentClasses ?? []),
                true,
            )
        );
    }

    /** @return list<string> */
    private function paths(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Scalar\String_) {
            return [$expression->value];
        }
        if (! $expression instanceof Node\Expr\Array_) {
            return [];
        }
        /** @var array<int|string, string|null> $effective */
        $effective = [];
        $next = 0;
        foreach ($expression->items as $item) {
            if ($item->unpack) {
                return [];
            }
            if ($item->key === null) {
                if ($next === null) {
                    return [];
                }
                $key = $next;
                $next = $key === PHP_INT_MAX ? null : $key + 1;
            } else {
                $key = $this->literalArrayKey($item->key);
                if ($key === null) {
                    return [];
                }
                if (is_int($key) && $key < 0) {
                    return [];
                }
                if ($next !== null && is_int($key) && $key >= $next) {
                    $next = $key === PHP_INT_MAX ? null : $key + 1;
                }
            }
            $effective[$key] = is_string($key) && ! is_numeric($key)
                ? $key
                : ($item->value instanceof Node\Scalar\String_ ? $item->value->value : null);
        }

        return array_values(array_unique(array_filter($effective, is_string(...))));
    }

    private function literalArrayKey(Node\Expr $expression): int|string|null
    {
        if ($expression instanceof Node\Scalar\Int_) {
            return $expression->value;
        }
        if (! $expression instanceof Node\Scalar\String_) {
            return null;
        }

        return array_key_first([$expression->value => null]);
    }
}
