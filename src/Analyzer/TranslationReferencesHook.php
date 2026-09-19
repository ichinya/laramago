<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeTranslationContract;
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

/** Check literal keys on native Lang and concrete Translator get calls. */
final class TranslationReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Lang';
    private const FACTORY = 'Illuminate\\Translation\\Translator';

    private ?ReferenceCatalogs $catalogs = null;
    private ?NativeTranslationContract $contract = null;
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
        return [MethodTarget::exact(self::FACADE, 'get'), MethodTarget::exact(self::FACTORY, 'get')];
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
        if ($call === null || $call->isFirstClassCallable()) {
            return;
        }
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), self::FACADE) !== 0
                || ! $this->nativeDispatch($context, self::FACADE)
            ) {
                return;
            }
        } elseif (
            $context->receiverType === null
            || count($context->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || $receiver->name !== self::FACTORY
            || ! $this->nativeDispatch($context, self::FACTORY)
        ) {
            return;
        }
        $arguments = [];
        $parameters = ['key', 'replace', 'locale', 'fallback'];
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
        $key = $arguments['key'] ?? null;
        if (
            ! $key instanceof Node\Scalar\String_
            || ! ($arguments['locale'] ?? null) instanceof Node\Scalar\String_
            || in_array($arguments['locale']->value, ['', '0'], true)
            || isset($arguments['fallback'])
            && (! $arguments['fallback'] instanceof Node\Expr\ConstFetch
            || strtolower($arguments['fallback']->name->toString()) !== 'true')
            || ! $this->catalogs()->missingTranslation($key->value, $arguments['locale']->value)
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-translation',
            Issue::at(
                'Literal translation reference "'
                .$key->value
                .'" is absent from the explicitly complete translations catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }

    private function nativeDispatch(NodeAnalysisContext $context, string $receiver): bool
    {
        $this->contract ??= new NativeTranslationContract($this->root);
        if (! $this->contract->matches($context->codebase)) {
            return false;
        }
        foreach ([
            'translator',
            self::FACTORY,
            'Illuminate\\Contracts\\Translation\\Translator',
            'translation.loader',
            'Illuminate\\Contracts\\Translation\\Loader',
        ] as $service) {
            if ($this->bindings()->configured($service)) {
                return false;
            }
        }
        if ($receiver === self::FACTORY) {
            return true;
        }
        $this->facade ??= new NativeFacade($this->root);

        return $this->facade->dispatchesClass($context->codebase, self::FACADE, 'translator', self::FACTORY, 'get');
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
            || strcasecmp($call->name->name, 'get') !== 0
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
