<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LaravelReferenceCallRegistry;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralStringArgument;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeTranslationContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
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

/** Check literal keys on native Lang and concrete Translator get and choice calls. */
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
        return [
            ...LaravelReferenceCallRegistry::targets(LaravelReferenceCallRegistry::TRANSLATION_GET),
            ...LaravelReferenceCallRegistry::targets(LaravelReferenceCallRegistry::TRANSLATION_CHOICE),
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
        if ($call === null || ! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return;
        }
        $choice = strtolower($call->name->toString()) === 'choice';
        $method = $choice ? 'choice' : 'get';
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
        $arguments = LaravelReferenceCallRegistry::arguments(
            $choice ? LaravelReferenceCallRegistry::TRANSLATION_CHOICE : LaravelReferenceCallRegistry::TRANSLATION_GET,
            $call,
        );
        if ($arguments === null) {
            return;
        }
        $key = LiteralStringArgument::from($arguments['key'] ?? null);
        $locale = LiteralStringArgument::from($arguments['locale'] ?? null);
        if (
            $key === null
            || $locale === null
            || in_array($locale->value, ['', '0'], true)
            || isset($arguments['fallback'])
            && (! $arguments['fallback'] instanceof Node\Expr\ConstFetch
            || strtolower($arguments['fallback']->name->toString()) !== 'true')
            || $choice
            && ! isset($arguments['number'])
            || ! (
                $choice
                    ? $this->catalogs()->missingTranslationChoice($key->value, $locale->value)
                    : $this->catalogs()->missingTranslation($key->value, $locale->value)
            )
        ) {
            return;
        }
        $issue = Issue::at(
            'Literal translation reference "'
            .$key->value
            .'" is absent from the explicitly complete translations catalog.',
            new SourceLocation($context->source->path, $context->node->span),
        );
        foreach ($choice
            ? $this->catalogs()->translationChoiceNotes($locale->value)
            : $this->catalogs()->translationLookupNotes($locale->value) as $note) {
            $issue = $issue->withNote($note);
        }
        $context->report(Level::Warning, 'laramago-missing-translation', $issue);
    }

    private function nativeDispatch(NodeAnalysisContext $context, string $receiver, string $method): bool
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

        return $this->facade->dispatchesClass($context->codebase, self::FACADE, 'translator', self::FACTORY, $method);
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
            || ! in_array(strtolower($call->name->name), ['get', 'choice'], true)
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
