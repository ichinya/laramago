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

/** Checks literal search keys separately from creation and update values. */
final class SearchAttributeReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const METHODS = ['firstOrNew', 'firstOrCreate', 'updateOrCreate'];

    private readonly EloquentModelDispatch $dispatch;
    private ?QuerySourceCatalog $sources = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];

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
    }

    public function getTargets(): array
    {
        return array_map(
            static fn (string $method): MethodTarget => MethodTarget::exact(self::BUILDER, $method),
            self::METHODS,
        );
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
        if ($call === null || ! $this->isNativeDirectCall($context, $model, $call)) {
            return;
        }
        foreach ($this->literalSearchKeys($call) ?? [] as $key) {
            if ($sources->contains($model, $key->value) !== false) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-query-source-missing-column',
                Issue::at(
                    'Search attribute "'
                    .$key->value
                    .'" is absent from the complete effective query source for '
                    .$model
                    .'.',
                    new SourceLocation(
                        $context->source->path,
                        new Span($key->getStartFilePos(), $key->getEndFilePos() + 1),
                    ),
                ),
            );
        }
    }

    private function model(NodeAnalysisContext $context): ?string
    {
        $receivers = $context->receiverType?->atomicTypes ?? [];
        if (
            count($receivers) !== 1
            || ! $receivers[0] instanceof NamedObjectType
            || strcasecmp($receivers[0]->name, self::BUILDER) !== 0
        ) {
            return null;
        }
        $models = $receivers[0]->parameters[0]->atomicTypes ?? [];

        return count($models) === 1 && $models[0] instanceof NamedObjectType ? $models[0]->name : null;
    }

    private function isNativeDirectCall(
        NodeAnalysisContext $context,
        string $model,
        Node\Expr\MethodCall $call,
    ): bool {
        if (
            ! $call->name instanceof Node\Identifier
            || $call->isFirstClassCallable()
            || ! $call->var instanceof Node\Expr\StaticCall
            || ! $call->var->class instanceof Node\Name
            || ! $call->var->name instanceof Node\Identifier
            || strcasecmp($call->var->name->name, 'query') !== 0
            || $call->var->isFirstClassCallable()
            || $call->var->args !== []
        ) {
            return false;
        }
        $root = $context->source->getResolvedName(
            new Span($call->var->class->getStartFilePos(), $call->var->class->getEndFilePos() + 1),
        )?->name;
        if ($root === null || strcasecmp($root, $model) !== 0) {
            return false;
        }
        $query = $context->codebase->getMethod($model, 'query') ?? $context->codebase->getDeclaringMethod(
            $model,
            'query',
        );
        $terminal = $context->codebase->getMethod(self::BUILDER, $call->name->name);

        return (
            $query !== null
            && strcasecmp($query->identifier->class ?? '', self::MODEL) === 0
            && $terminal !== null
            && strcasecmp($terminal->identifier->class ?? '', self::BUILDER) === 0
            && $this->dispatch->supportsModel($context->codebase, $model, $call->name->name)
        );
    }

    /** @return list<Node\Scalar\String_>|null */
    private function literalSearchKeys(Node\Expr\MethodCall $call): ?array
    {
        foreach ($call->args as $argument) {
            if ($argument instanceof Node\Arg && $argument->unpack) {
                return null;
            }
        }
        $attributes = $this->argument($call->args, 0, 'attributes');
        if ($attributes === null) {
            return [];
        }
        if (! $attributes instanceof Node\Expr\Array_) {
            return null;
        }
        $keys = [];
        foreach ($attributes->items as $item) {
            if (
                $item->unpack
                || $item->byRef
                || ! $item->key instanceof Node\Scalar\String_
                || is_numeric($item->key->value)
                || array_key_exists($item->key->value, $keys)
            ) {
                return null;
            }
            $keys[$item->key->value] = $item->key;
        }

        return array_values($keys);
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

    private function call(NodeAnalysisContext $context): ?Node\Expr\MethodCall
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents) ?? [];
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
