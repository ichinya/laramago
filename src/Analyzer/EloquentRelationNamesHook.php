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
use PhpParser\ParserFactory;

/** Validate literal relation paths without evaluating application methods. */
final class EloquentRelationNamesHook implements MethodCallAnalysisHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const RELATION = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::BUILDER, 'with'),
            MethodTarget::exact(self::BUILDER, 'whereHas'),
            MethodTarget::exact(self::MODEL, 'load'),
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
        $receiver = $this->object($context->receiverType);
        if ($receiver === null) {
            return;
        }
        $model = $receiver->name === self::BUILDER
            ? $this->object($receiver->parameters[0] ?? null)?->name
            : $receiver->name;
        if ($model === null || $model === self::MODEL || ! $this->isA($context->codebase, $model, self::MODEL)) {
            return;
        }
        foreach (['__call', 'relationResolver'] as $name) {
            $method = $context->codebase->getDeclaringMethod($model, $name);
            if (
                $method !== null
                && ! in_array(
                    $method->identifier->class,
                    [self::MODEL, 'Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships'],
                    true,
                )
            ) {
                return;
            }
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse('<?php '.$context->source->getText($context->node).';');
        } catch (\PhpParser\Error) {
            return;
        }
        $statement = $nodes[0] ?? null;
        $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ! $call->name instanceof Node\Identifier
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $methodName = strtolower($call->name->name);
        $owner = $context->codebase->getDeclaringMethod($receiver->name, $methodName)?->identifier->class;
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
                $method = $context->codebase->getDeclaringMethod($current, $segment);
                // An absent declaration may be registered through resolveRelationUsing().
                // Do not turn incomplete static information into an error.
                if ($method === null) {
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
