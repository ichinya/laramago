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
            MethodTarget::exact(self::BUILDER, 'with'),
            MethodTarget::exact(self::BUILDER, 'whereHas'),
            MethodTarget::exact(self::MODEL, 'load'),
            MethodTarget::exact(self::MODEL, 'with'),
            MethodTarget::exact(self::MODEL, 'whereHas'),
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
            if (! $call->class instanceof Node\Name\FullyQualified || $methodName === 'load') {
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
                && $methodName === 'wherehas'
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
        foreach ($call->getArgs() as $offset => $arg) {
            if ($arg->unpack) {
                return;
            }
            if (
                $arg->name === null
                && $offset === 0
                || $arg->name !== null
                && $arg->name->name === ($methodName === 'wherehas' ? 'relation' : 'relations')
            ) {
                $argument = $arg->value;
            }
        }
        if ($argument === null) {
            return;
        }
        foreach ($this->paths($argument) as $path) {
            $current = $model;
            foreach (explode('.', explode(':', $path, 2)[0]) as $segment) {
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
        $paths = [];
        foreach ($expression->items as $item) {
            if ($item->unpack) {
                continue;
            }
            $value = $item->key instanceof Node\Scalar\String_ ? $item->key : $item->value;
            if ($value instanceof Node\Scalar\String_) {
                $paths[] = $value->value;
            }
        }

        return array_values(array_unique($paths));
    }
}
