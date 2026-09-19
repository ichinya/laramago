<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeConditionalViewContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
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
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Check only the statically selected branch of native conditional view rendering. */
final class ConditionalViewReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const FACADE = 'Illuminate\\Support\\Facades\\View';
    private const FACTORY = 'Illuminate\\View\\Factory';

    private ?ReferenceCatalogs $catalogs = null;
    private ?NativeConditionalViewContract $contract = null;
    private ?NativeFacade $facade = null;
    private ?ContainerBindings $bindings = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalogs = null;
        $this->contract = null;
        $this->facade = null;
        $this->bindings = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact(self::FACADE, 'renderWhen'),
            MethodTarget::exact(self::FACTORY, 'renderWhen'),
            MethodTarget::exact(self::FACADE, 'renderUnless'),
            MethodTarget::exact(self::FACTORY, 'renderUnless'),
            MethodTarget::exact(self::FACADE, 'renderEach'),
            MethodTarget::exact(self::FACTORY, 'renderEach'),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->catalogs()->enabled()) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
            return;
        }
        $method = $call->name->name;
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), self::FACADE) !== 0
                || ! $this->nativeDispatch($context, self::FACADE, $method)
            ) {
                return;
            }
        } elseif (
            $context->receiverType === null
            || count($context->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || $receiver->name !== self::FACTORY
            || ! $this->nativeDispatch($context, self::FACTORY, $method)
        ) {
            return;
        }
        $arguments = [];
        $method = strtolower($call->name->toString());
        $parameters = $method === 'rendereach'
            ? ['view', 'data', 'iterator', 'empty']
            : ['condition', 'view', 'data', 'mergeData'];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $argument->unpack
                || $argument->byRef
                || $parameter === null
                || ! in_array($parameter, $parameters, true)
                || isset($arguments[$parameter])
                || $named
                && $argument->name === null
            ) {
                return;
            }
            $named = $argument->name !== null;
            $arguments[$parameter] = $argument->value;
        }
        if (! isset($arguments['view'])) {
            return;
        }
        $view = $arguments['view'];
        if ($method === 'rendereach') {
            $data = $arguments['data'] ?? null;
            if (
                ! $data instanceof Node\Expr\Array_
                || ! ($arguments['iterator'] ?? null) instanceof Node\Scalar\String_
            ) {
                return;
            }
            foreach ($data->items as $item) {
                if (
                    $item->unpack
                    || $item->byRef
                    || $item->key !== null
                    && ! $item->key instanceof Node\Scalar\String_
                    && ! $item->key instanceof Node\Scalar\Int_
                ) {
                    return;
                }
            }
            if ($data->items === []) {
                $view = $arguments['empty'] ?? null;
                if ($view instanceof Node\Scalar\String_ && str_starts_with($view->value, 'raw|')) {
                    return;
                }
            }
        } else {
            $condition = $arguments['condition'] ?? null;
            if (
                ! $condition instanceof Node\Expr\ConstFetch
                || strtolower($condition->name->toString()) !== ($method === 'renderwhen' ? 'true' : 'false')
            ) {
                return;
            }
            // Do not execute Arrayable conversion to discover whether make() is reached.
            foreach (['data', 'mergeData'] as $parameter) {
                if (isset($arguments[$parameter]) && ! $arguments[$parameter] instanceof Node\Expr\Array_) {
                    return;
                }
            }
        }
        if (
            ! $view instanceof Node\Scalar\String_
            || ! $this->catalogs()->missingView($view->value)
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-view',
            Issue::at(
                'Literal view reference "'.$view->value.'" is absent from the explicitly complete views catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }

    private function nativeDispatch(NodeAnalysisContext $context, string $receiver, string $method): bool
    {
        $this->contract ??= new NativeConditionalViewContract($this->root);
        if (! $this->contract->matches($context->codebase)) {
            return false;
        }
        foreach ([
            'view',
            self::FACTORY,
            'Illuminate\\Contracts\\View\\Factory',
            'view.finder',
            'Illuminate\\View\\ViewFinderInterface',
            'Illuminate\\View\\FileViewFinder',
        ] as $service) {
            if ($this->bindings()->configured($service)) {
                return false;
            }
        }
        if ($receiver === self::FACTORY) {
            return true;
        }
        $this->facade ??= new NativeFacade($this->root);

        return $this->facade->dispatchesClass($context->codebase, self::FACADE, 'view', self::FACTORY, $method);
    }

    private function call(NodeAnalysisContext $context): Node\Expr\MethodCall|Node\Expr\StaticCall|null
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $call) {
                if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
                    continue;
                }
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            && ! $call instanceof Node\Expr\StaticCall
            || ! $call->name instanceof Node\Identifier
            || ! in_array(strtolower($call->name->name), ['renderwhen', 'renderunless', 'rendereach'], true)
        ) {
            return null;
        }

        return $call;
    }

    private function catalogs(): ReferenceCatalogs
    {
        return $this->catalogs ??= new ReferenceCatalogs($this->root);
    }

    private function bindings(): ContainerBindings
    {
        return $this->bindings ??= new ContainerBindings($this->root);
    }
}
