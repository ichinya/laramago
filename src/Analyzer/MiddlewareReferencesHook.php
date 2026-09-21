<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\MiddlewareReferenceCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeRouteMiddlewareContract;
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

/** Check native Route literal middleware calls against an explicit permitted-reference policy. */
final class MiddlewareReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const ROUTE = 'Illuminate\\Routing\\Route';

    private ?MiddlewareReferenceCatalog $catalog = null;
    private ?NativeRouteMiddlewareContract $contract = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = null;
        $this->contract = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ROUTE, 'middleware')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $catalog = $this->catalog ??= new MiddlewareReferenceCatalog($this->root);
        if (! $catalog->isComplete()) {
            return;
        }
        $call = $this->call($context);
        $atoms = $context->receiverType?->atomicTypes ?? [];
        if (
            $call === null
            || count($atoms) !== 1
            || ! $atoms[0] instanceof NamedObjectType
            || $atoms[0]->name !== self::ROUTE
            || ! ($this->contract ??= new NativeRouteMiddlewareContract($this->root))->matches($context->codebase)
        ) {
            return;
        }
        $references = $this->references($call);
        if ($references === null) {
            return;
        }
        foreach ($references as $reference) {
            if ($catalog->contains($reference->value) !== false) {
                continue;
            }
            $encoded = json_encode($reference->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $context->report(
                Level::Warning,
                'laramago-uncataloged-middleware-reference',
                Issue::at(
                    'Middleware reference '
                    .($encoded === false ? '"<unprintable>"' : $encoded)
                    .' is not listed in the configured permitted middleware reference policy.',
                    new SourceLocation(
                        $context->source->path,
                        new Span($reference->getStartFilePos(), $reference->getEndFilePos() + 1),
                    ),
                ),
            );
        }
    }

    /** @return list<Node\Scalar\String_>|null */
    private function references(Node\Expr\MethodCall $call): ?array
    {
        $arguments = $call->getArgs();
        if ($arguments === []) {
            return [];
        }
        if (count($arguments) !== 1) {
            return null;
        }
        foreach ($arguments as $offset => $argument) {
            if (
                $argument->unpack
                || $argument->byRef
                || $argument->name !== null
                && ($offset !== 0
                || $argument->name->toString() !== 'middleware')
            ) {
                return null;
            }
        }
        if ($arguments[0]->value instanceof Node\Expr\Array_) {
            $references = [];
            foreach ($arguments[0]->value->items as $item) {
                if (
                    $item->key !== null
                    || $item->unpack
                    || $item->byRef
                    || ! $item->value instanceof Node\Scalar\String_
                ) {
                    return null;
                }
                $references[] = $item->value;
            }

            return $references;
        }

        return $arguments[0]->value instanceof Node\Scalar\String_ ? [$arguments[0]->value] : null;
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
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== 'middleware'
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        return $call;
    }
}
