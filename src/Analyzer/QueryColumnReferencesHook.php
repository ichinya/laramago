<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

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

/** Checks literal columns only in complete, inline queries with an explicit source contract. */
final class QueryColumnReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';
    private const FILTERS = [
        'where',
        'orwhere',
        'wherenot',
        'orwherenot',
        'wherecolumn',
        'orwherecolumn',
        'wherein',
        'orwherein',
        'wherenotin',
        'orwherenotin',
        'wherenull',
        'orwherenull',
        'wherenotnull',
        'orwherenotnull',
        'wherebetween',
        'orwherebetween',
        'wherenotbetween',
        'orwherenotbetween',
        'wheredate',
        'orwheredate',
        'wheretime',
        'orwheretime',
        'whereday',
        'orwhereday',
        'wheremonth',
        'orwheremonth',
        'whereyear',
        'orwhereyear',
        'orderby',
        'orderbydesc',
        'latest',
        'oldest',
    ];
    private const TERMINALS = ['get', 'first', 'firstorfail', 'sole'];
    private const ELOQUENT_METHODS = ['where', 'orwhere', 'wherenot', 'orwherenot', 'latest', 'oldest'];

    private ?QuerySourceCatalog $sources = null;
    private readonly EloquentModelDispatch $dispatch;
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
        $this->sourceHash = null;
        $this->calls = [];
        $this->parentCalls = [];
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ([self::MODEL, self::BUILDER, self::QUERY] as $class) {
            foreach (self::FILTERS as $method) {
                $targets[] = MethodTarget::exact($class, $method);
            }
        }

        return $targets;
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ReceiverType];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $model = $this->model($context);
        $sources = $this->sources ??= new QuerySourceCatalog($this->projectRoot);
        if ($model === null || ! $sources->has($model)) {
            return;
        }
        $call = $this->call($context);
        if ($call === null) {
            return;
        }
        $chain = $this->completeChain($call);
        if ($chain === null || ! $this->nativeChain($context, $model, $chain)) {
            return;
        }
        foreach ($this->columnNodes($call) ?? [] as $column) {
            if ($sources->contains($model, $column->value) !== false) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-query-source-missing-column',
                Issue::at(
                    'Column reference "'
                    .$column->value
                    .'" is absent from the complete effective query source for '
                    .$model
                    .'.',
                    new SourceLocation(
                        $context->source->path,
                        new Span($column->getStartFilePos(), $column->getEndFilePos() + 1),
                    ),
                ),
            );
        }
    }

    /** @return list<Node\Expr\MethodCall|Node\Expr\StaticCall>|null */
    private function completeChain(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?array
    {
        $terminal = $call instanceof Node\Expr\MethodCall
            ? $call
            : $this->parentCalls[spl_object_id($call)] ?? null;
        if ($terminal === null) {
            return null;
        }
        while (($parent = $this->parentCalls[spl_object_id($terminal)] ?? null) !== null) {
            $terminal = $parent;
        }
        if (
            ! $terminal->name instanceof Node\Identifier
            || ! in_array(strtolower($terminal->name->name), self::TERMINALS, true)
            || $terminal->isFirstClassCallable()
            || $terminal->args !== []
        ) {
            return null;
        }
        /** @var list<Node\Expr\MethodCall|Node\Expr\StaticCall> $chain */
        $chain = [];
        $cursor = $terminal;
        while ($cursor instanceof Node\Expr\MethodCall) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->var;
        }
        if (! $cursor instanceof Node\Expr\StaticCall || ! $cursor->class instanceof Node\Name) {
            return null;
        }
        array_unshift($chain, $cursor);
        if (! $cursor->name instanceof Node\Identifier) {
            return null;
        }
        $rootName = strtolower($cursor->name->name);
        if ($rootName !== 'query' || $cursor->isFirstClassCallable() || $cursor->args !== []) {
            return null;
        }
        foreach (array_slice($chain, 1, -1) as $step) {
            if (
                ! $step instanceof Node\Expr\MethodCall
                || ! $step->name instanceof Node\Identifier
                || ! in_array(strtolower($step->name->name), self::FILTERS, true)
                || $this->columnNodes($step) === null
            ) {
                return null;
            }
        }

        return in_array($call, $chain, true) ? $chain : null;
    }

    /** @param list<Node\Expr\MethodCall|Node\Expr\StaticCall> $chain */
    private function nativeChain(NodeAnalysisContext $context, string $model, array $chain): bool
    {
        $root = $chain[0] ?? null;
        $rootClass =
            $root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name
                ? $context->source->getResolvedName(
                    new Span($root->class->getStartFilePos(), $root->class->getEndFilePos() + 1),
                )?->name
                : null;
        if ($rootClass === null || strcasecmp($rootClass, $model) !== 0) {
            return false;
        }
        if (! $this->dispatch->supportsModel($context->codebase, $model, 'where')) {
            return false;
        }
        foreach (array_slice($chain, 0, -1) as $index => $step) {
            if (! $step->name instanceof Node\Identifier) {
                return false;
            }
            $name = strtolower($step->name->name);
            if ($index === 0 && $name === 'query') {
                continue;
            }
            if (in_array($name, self::ELOQUENT_METHODS, true)) {
                $method = $context->codebase->getMethod(self::BUILDER, $name);
                if ($method === null || strcasecmp($method->identifier->class ?? '', self::BUILDER) !== 0) {
                    return false;
                }
                continue;
            }
            $method = $context->codebase->getMethod(self::QUERY, $name);
            if (
                $method === null
                || strcasecmp($method->identifier->class ?? '', self::QUERY) !== 0
                || $context->codebase->getMethod(self::BUILDER, $name) !== null
                || $context->codebase->getMethod($model, $name) !== null
                || $context->codebase->getDeclaringMethod($model, $name) !== null
                || $context->codebase->getDeclaringMethod($model, 'scope'.ucfirst($name)) !== null
                || $this->dispatch->overrides($context->codebase, $model, 'hasNamedScope')
                || $this->dispatch->overrides($context->codebase, $model, 'callNamedScope')
                || $this->dispatch->overrides($context->codebase, $model, 'isScopeMethodWithAttribute')
            ) {
                return false;
            }
        }
        $terminal = end($chain);
        $terminalName =
            $terminal instanceof Node\Expr\MethodCall && $terminal->name instanceof Node\Identifier
                ? strtolower($terminal->name->name)
                : '';
        $terminalMethod = $context->codebase->getMethod(self::BUILDER, $terminalName);

        return $terminalMethod !== null && strcasecmp($terminalMethod->identifier->class ?? '', self::BUILDER) === 0;
    }

    /** @return list<Node\Scalar\String_>|null */
    private function columnNodes(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?array
    {
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return null;
        }
        foreach ($call->args as $argument) {
            if ($argument instanceof Node\Arg && $argument->unpack) {
                return null;
            }
        }
        $name = strtolower($call->name->name);
        $first = $this->argument($call->args, 0, 'column', 'columns', 'first');
        if (! $first instanceof Node\Scalar\String_) {
            return null;
        }
        if (! in_array($name, ['wherecolumn', 'orwherecolumn'], true)) {
            return [$first];
        }
        $second = $this->argument($call->args, 2, 'second');
        if (
            $second === null
            && count($call->args) === 2
            && $call->args[1] instanceof Node\Arg
            && $call->args[1]->name === null
        ) {
            $second = $call->args[1]->value;
        }

        return $second === null ? [$first] : ($second instanceof Node\Scalar\String_ ? [$first, $second] : null);
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function argument(array $arguments, int $position, string ...$names): ?Node\Expr
    {
        $argument = $arguments[$position] ?? null;
        if ($argument instanceof Node\Arg && $argument->name === null) {
            return $argument->value;
        }
        foreach ($arguments as $candidate) {
            if (
                $candidate instanceof Node\Arg
                && $candidate->name !== null
                && in_array($candidate->name->name, $names, true)
            ) {
                return $candidate->value;
            }
        }

        return null;
    }

    private function model(NodeAnalysisContext $context): ?string
    {
        $receiverType = $context->receiverType;
        if ($receiverType === null) {
            return null;
        }
        $atoms = $receiverType->atomicTypes;
        if (count($atoms) !== 1 || ! $atoms[0] instanceof NamedObjectType) {
            return null;
        }
        $receiver = $atoms[0];
        if (strcasecmp($receiver->name, self::BUILDER) === 0) {
            $modelAtoms = $receiver->parameters[0]->atomicTypes ?? [];
            $model = count($modelAtoms) === 1 ? $modelAtoms[0] : null;

            return $model instanceof NamedObjectType ? $model->name : null;
        }

        return $context->types->isContainedBy($receiverType, \Mago\Sdk\Analyzer\Type::namedObject(self::MODEL))
            ? $receiver->name
            : null;
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) {
                    $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
                    if ($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\CallLike) {
                        $this->parentCalls[spl_object_id($call->var)] = $call;
                    }
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
