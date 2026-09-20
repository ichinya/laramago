<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LaravelReferenceCallRegistry;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralStringArgument;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeResponseViewContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeViewFactoryContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Literal view names forwarded through Laravel's response factory. */
final class ResponseViewReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const FACTORY = 'Illuminate\\Routing\\ResponseFactory';
    private const CONTRACT = 'Illuminate\\Contracts\\Routing\\ResponseFactory';
    private const FACADE = 'Illuminate\\Support\\Facades\\Response';

    private ?ReferenceCatalogs $catalog = null;
    private ?ContainerBindings $bindings = null;
    private ?NativeFacade $facade = null;
    private ?bool $nativeResponse = null;
    private ?bool $nativeView = null;
    private ?bool $nativeHelpers = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = null;
        $this->bindings = null;
        $this->facade = null;
        $this->nativeResponse = null;
        $this->nativeView = null;
        $this->nativeHelpers = null;
        $this->sourceHash = null;
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::MethodCall, NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $catalog = $this->catalog ??= new ReferenceCatalogs($this->root);
        if (! $catalog->enabled()) {
            return;
        }
        $call = $this->call($context);
        if (
            $call === null
            || $call->isFirstClassCallable()
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== LaravelReferenceCallRegistry::method(LaravelReferenceCallRegistry::RESPONSE_VIEW)
            || $this->customService()
            || ! ($this->nativeResponse ??= (new NativeResponseViewContract($this->root))->matches($context->codebase))
            || ! ($this->nativeView ??= (new NativeViewFactoryContract($this->root))->matches($context->codebase))
        ) {
            return;
        }
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), self::FACADE) !== 0
                || ! ($this->facade ??= new NativeFacade($this->root))->dispatchesClass(
                    $context->codebase,
                    self::FACADE,
                    self::CONTRACT,
                    self::FACTORY,
                    'view',
                )
            ) {
                return;
            }
        } else {
            $atoms = $context->receiverType?->atomicTypes ?? [];
            $receiver = $atoms[0] ?? null;
            if (
                count($atoms) !== 1
                || ! $receiver instanceof NamedObjectType
                || $receiver->name !== self::FACTORY
                && ($receiver->name !== self::CONTRACT
                || ! $this->nativeResponseHelper($context, $call->var))
            ) {
                return;
            }
        }
        $arguments = LaravelReferenceCallRegistry::arguments(LaravelReferenceCallRegistry::RESPONSE_VIEW, $call);
        if ($arguments === null) {
            return;
        }
        $name = LiteralStringArgument::from($arguments['view'] ?? null);
        if ($name === null || ! $catalog->missingView($name->value)) {
            return;
        }
        $issue = Issue::at(
            'View "'.$name->value.'" is absent from the explicitly complete view catalog.',
            new SourceLocation(
                $context->source->path,
                new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
            ),
        );
        foreach ($catalog->viewSuggestionNotes($name->value) as $note) {
            $issue = $issue->withNote($note);
        }
        $context->report(
            Level::Warning,
            'laramago-missing-view',
            $issue,
        );
    }

    private function customService(): bool
    {
        $this->bindings ??= new ContainerBindings($this->root);
        foreach ([
            self::CONTRACT,
            self::FACTORY,
            'view',
            'Illuminate\\Contracts\\View\\Factory',
            'Illuminate\\View\\Factory',
            'view.finder',
            'Illuminate\\View\\ViewFinderInterface',
            'Illuminate\\View\\FileViewFinder',
        ] as $service) {
            if ($this->bindings->configured($service)) {
                return true;
            }
        }

        return false;
    }

    private function nativeResponseHelper(NodeAnalysisContext $context, Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\FuncCall
            || ! $expression->name instanceof Node\Name
            || $expression->args !== []
            || $expression->isFirstClassCallable()
        ) {
            return false;
        }
        $name = $expression->name->toString();
        /** @var mixed $fallback */
        $fallback = $expression->name->getAttribute('namespacedName');
        if (
            $fallback instanceof Node\Name
            && $context->codebase->getFunction($fallback->toString()) !== null
            || strcasecmp($name, 'response') !== 0
        ) {
            return false;
        }

        return $this->nativeHelpers ??= (new NativeResponseViewContract($this->root))->helpersMatch($context->codebase);
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
                if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) {
                    $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
