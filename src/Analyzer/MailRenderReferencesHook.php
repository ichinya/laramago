<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeMailRenderContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeViewFactoryContract;
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
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Immediate native mail rendering, under an explicit effective view factory contract. */
final class MailRenderReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private ?ReferenceCatalogs $catalog = null;
    /** @var array<string, bool> */
    private array $native = [];
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];
    private bool $enabled = false;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $text = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        $this->enabled =
            is_array($composer)
            && ($composer['extra']['laramago']['mail-render-contract']['native-view-factory'] ?? null) === true;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = null;
        $this->native = [];
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact('Illuminate\\Mail\\Mailer', 'render')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->enabled) {
            return;
        }
        $catalog = $this->catalog ??= new ReferenceCatalogs($this->root);
        if (! $catalog->enabled()) {
            return;
        }
        $atoms = $context->receiverType?->atomicTypes ?? [];
        $receiver = $atoms[0] ?? null;
        if (
            count($atoms) !== 1
            || ! $receiver instanceof NamedObjectType
            || $receiver->name !== 'Illuminate\\Mail\\Mailer'
        ) {
            return;
        }
        if (! array_key_exists('matches', $this->native)) {
            $this->native['matches'] = $this->nativeDispatch($context);
        }
        if (! $this->native['matches']) {
            return;
        }
        $call = $this->call($context);
        if (
            $call === null
            || $call->isFirstClassCallable()
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== 'render'
        ) {
            return;
        }
        $arguments = [];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            $parameter = $argument->name?->name ?? ['view', 'data'][$offset] ?? null;
            if (
                $argument->unpack
                || $argument->byRef
                || $parameter === null
                || ! in_array($parameter, ['view', 'data'], true)
                || isset($arguments[$parameter])
                || $named
                && $argument->name === null
            ) {
                return;
            }
            $named = $argument->name !== null;
            $arguments[$parameter] = $argument->value;
        }
        $name = $arguments['view'] ?? null;
        // Empty and "0" select the plain fallback in native render; arrays/closures are deferred.
        if (
            ! $name instanceof Node\Scalar\String_
            || $name->value === ''
            || $name->value === '0'
            || ! $catalog->missingView($name->value)
        ) {
            return;
        }
        $issue = Issue::at(
            'Mail view "'.$name->value.'" is absent from the explicitly complete view catalog.',
            new SourceLocation($context->source->path, new Span($name->getStartFilePos(), $name->getEndFilePos() + 1)),
        );
        foreach ($catalog->viewSuggestionNotes($name->value) as $note) {
            $issue = $issue->withNote($note);
        }
        $context->report(Level::Warning, 'laramago-missing-view', $issue);
    }

    private function nativeDispatch(NodeAnalysisContext $context): bool
    {
        $bindings = new ContainerBindings($this->root);
        foreach ([
            'view',
            'Illuminate\\Contracts\\View\\Factory',
            'Illuminate\\View\\Factory',
            'view.finder',
            'Illuminate\\View\\ViewFinderInterface',
            'Illuminate\\View\\FileViewFinder',
        ] as $service) {
            if ($bindings->configured($service)) {
                return false;
            }
        }

        return (
            (new NativeMailRenderContract($this->root))->matches($context->codebase)
            && (new NativeViewFactoryContract($this->root))->matches($context->codebase)
        );
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
                    ->parse($context->source->contents);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes ?? [], Node\Expr\MethodCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
