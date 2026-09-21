<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RelationMethodInference;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Check selected eager-load columns when the complete related query source is proven. */
final class RelatedProjectionColumnsHook implements MethodCallAnalysisHook, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const RELATION = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';
    private const SUPPORTED_RELATIONS = [
        'Illuminate\\Database\\Eloquent\\Relations\\HasOne',
        'Illuminate\\Database\\Eloquent\\Relations\\HasMany',
        'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo',
        'Illuminate\\Database\\Eloquent\\Relations\\MorphOne',
        'Illuminate\\Database\\Eloquent\\Relations\\MorphMany',
    ];
    private const TERMINALS = ['get', 'first', 'firstorfail', 'sole'];

    private readonly EloquentModelDispatch $dispatch;
    private ?RelatedProjectionSourceCatalog $sources = null;
    private ?PhpSource $phpSource = null;
    private ?string $sourceHash = null;

    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    /** @var array<int, Node\Expr\MethodCall> */
    private array $parentCalls = [];

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $this->dispatch = new EloquentModelDispatch;
    }

    public function initialize(InitializationContext $context): void
    {
        $this->sources = null;
        $this->phpSource = null;
        $this->sourceHash = null;
        $this->calls = [];
        $this->parentCalls = [];
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::BUILDER, 'with')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $model = $this->model($context);
        $sources = $this->sources ??= new RelatedProjectionSourceCatalog($this->projectRoot);
        if ($model === null || ! $sources->hasOwner($model)) {
            return;
        }
        $call = $this->call($context);
        if (! $call instanceof Node\Expr\MethodCall) {
            return;
        }
        $chain = $this->completeChain($call);
        if ($chain === null || ! $this->nativeChain($context, $model, $chain)) {
            return;
        }
        $selection = $this->selection($call);
        if ($selection === null || ! $this->standardRelationDispatch($context->codebase, $model)) {
            return;
        }
        [$relation, $columns, $literal] = $selection;
        $relationType = (new RelationMethodInference(
            $context->codebase,
            $this->phpSource ??= new PhpSource($this->projectRoot),
        ))->infer($model, $relation);
        $relationObject = $this->object($relationType?->atomicTypes ?? []);
        if ($relationObject === null || ! in_array($relationObject->name, self::SUPPORTED_RELATIONS, true)) {
            return;
        }
        $related = $this->object($relationObject->parameters[0]->atomicTypes ?? []);
        if (
            $related === null
            || ! $this->dispatch->supportsModel($context->codebase, $related->name, 'select')
            || ! $this->standardRelatedDispatch($context->codebase, $related->name)
        ) {
            return;
        }
        foreach ($columns as $column) {
            if ($sources->contains($model, $relation, $related->name, $column) !== false) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-related-projection-missing-column',
                Issue::at(
                    'Eager-load projection column "'
                    .$column
                    .'" is absent from the complete effective eager relation source for '
                    .$related->name
                    .' (relation '
                    .$model
                    .'::'
                    .$relation
                    .').',
                    new SourceLocation(
                        $context->source->path,
                        new Span($literal->getStartFilePos(), $literal->getEndFilePos() + 1),
                    ),
                ),
            );
        }
    }

    /** @param array{Node\Expr\StaticCall, Node\Expr\MethodCall, Node\Expr\MethodCall} $chain */
    private function nativeChain(NodeAnalysisContext $context, string $model, array $chain): bool
    {
        [$root, , $terminal] = $chain;
        if (! $terminal->name instanceof Node\Identifier) {
            return false;
        }
        $terminalName = $terminal->name->name;
        $rootClass = $root->class instanceof Node\Name
            ? $context->source->getResolvedName(
                new Span($root->class->getStartFilePos(), $root->class->getEndFilePos() + 1),
            )?->name
            : null;
        if (
            $rootClass === null
            || strcasecmp($rootClass, $model) !== 0
            || ! $this->dispatch->supportsModel($context->codebase, $model, 'with')
            || $this->dispatch->overrides($context->codebase, $model, 'newInstance')
        ) {
            return false;
        }
        foreach ([
            [self::MODEL, 'query', self::MODEL],
            [self::BUILDER, 'with', self::BUILDER],
            [self::BUILDER, $terminalName, self::BUILDER],
            [self::BUILDER, 'parseWithRelations', self::BUILDER],
            [self::BUILDER, 'parseNameAndAttributeSelectionConstraint', self::BUILDER],
            [self::BUILDER, 'createSelectWithConstraint', self::BUILDER],
        ] as [$class, $method, $owner]) {
            $metadata = $context->codebase->getDeclaringMethod($class, $method) ?? $context->codebase->getMethod(
                $class,
                $method,
            );
            if ($metadata === null || strcasecmp($metadata->identifier->class ?? '', $owner) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function standardRelationDispatch(Codebase $codebase, string $model): bool
    {
        foreach (['__call', 'relationResolver'] as $method) {
            $owner = $codebase->getDeclaringMethod($model, $method)?->identifier->class;
            if (
                $owner !== null
                && ! in_array(
                    strtolower($owner),
                    [
                        strtolower(self::MODEL),
                        'illuminate\\database\\eloquent\\concerns\\hasrelationships',
                    ],
                    true,
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function standardRelatedDispatch(Codebase $codebase, string $model): bool
    {
        $relationCall = $codebase->getDeclaringMethod(self::RELATION, '__call');
        $builderCall = $codebase->getDeclaringMethod(self::BUILDER, '__call');

        return (
            $relationCall !== null
            && strcasecmp($relationCall->identifier->class ?? '', self::RELATION) === 0
            && $builderCall !== null
            && strcasecmp($builderCall->identifier->class ?? '', self::BUILDER) === 0
            && ! $this->dispatch->overrides($codebase, $model, 'newInstance')
        );
    }

    /**
     * @return array{string, list<string>, Node\Scalar\String_}|null
     */
    private function selection(Node\Expr\MethodCall $call): ?array
    {
        if (count($call->args) !== 1 || ! $call->args[0] instanceof Node\Arg || $call->args[0]->unpack) {
            return null;
        }
        $argument = $call->args[0];
        if ($argument->name !== null && $argument->name->name !== 'relations') {
            return null;
        }
        if (! $argument->value instanceof Node\Scalar\String_) {
            return null;
        }
        $literal = $argument->value;
        $parts = explode(':', $literal->value, 2);
        if (count($parts) !== 2 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parts[0]) !== 1) {
            return null;
        }
        $columns = explode(',', $parts[1]);
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column) !== 1) {
                return null;
            }
        }

        return [$parts[0], $columns, $literal];
    }

    /**
     * @return array{Node\Expr\StaticCall, Node\Expr\MethodCall, Node\Expr\MethodCall}|null
     */
    private function completeChain(Node\Expr\MethodCall $call): ?array
    {
        $terminal = $this->parentCalls[spl_object_id($call)] ?? null;
        if (
            $terminal === null
            || isset($this->parentCalls[spl_object_id($terminal)])
            || ! $terminal->name instanceof Node\Identifier
            || ! in_array(strtolower($terminal->name->name), self::TERMINALS, true)
            || $terminal->isFirstClassCallable()
            || $terminal->args !== []
        ) {
            return null;
        }
        $root = $call->var;
        if (
            ! $root instanceof Node\Expr\StaticCall
            || ! $root->class instanceof Node\Name
            || ! $root->name instanceof Node\Identifier
            || strtolower($root->name->name) !== 'query'
            || $root->isFirstClassCallable()
            || $root->args !== []
        ) {
            return null;
        }

        return [$root, $call, $terminal];
    }

    private function model(NodeAnalysisContext $context): ?string
    {
        $atoms = $context->receiverType?->atomicTypes ?? [];
        $receiver = count($atoms) === 1 ? $atoms[0] : null;
        if (! $receiver instanceof NamedObjectType || strcasecmp($receiver->name, self::BUILDER) !== 0) {
            return null;
        }

        return $this->object($receiver->parameters[0]->atomicTypes ?? [])?->name;
    }

    /** @param list<mixed> $atoms */
    private function object(array $atoms): ?NamedObjectType
    {
        return count($atoms) === 1 && $atoms[0] instanceof NamedObjectType ? $atoms[0] : null;
    }

    private function call(NodeAnalysisContext $context): Node\Expr\MethodCall|Node\Expr\StaticCall|null
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            $this->parentCalls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents) ?? [];
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $candidate) {
                if (! $candidate instanceof Node\Expr\MethodCall && ! $candidate instanceof Node\Expr\StaticCall) {
                    continue;
                }
                $this->calls[$candidate->getStartFilePos().':'.($candidate->getEndFilePos() + 1)] = $candidate;
                if ($candidate instanceof Node\Expr\MethodCall && $candidate->var instanceof Node\Expr\CallLike) {
                    $this->parentCalls[spl_object_id($candidate->var)] = $candidate;
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
