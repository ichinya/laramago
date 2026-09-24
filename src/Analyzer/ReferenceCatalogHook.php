<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LaravelReferenceCallRegistry;
use Ichinya\Laramago\Analyzer\StaticAnalysis\LiteralStringArgument;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeTranslationContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Diagnose literal references only under an explicit complete-catalog contract. */
final class ReferenceCatalogHook implements NodeAnalysisHook, InitializationHook
{
    private ?ReferenceCatalogs $catalogs = null;
    private ?NativeTranslationContract $translationContract = null;
    private ?ContainerBindings $bindings = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall> */
    private array $calls = [];

    public function initialize(InitializationContext $context): void
    {
        $this->catalogs = null;
        $this->translationContract = null;
        $this->bindings = null;
        $this->sourceHash = null;
        $this->calls = [];
    }

    public function __construct(
        private readonly string $root,
    ) {}

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $this->catalogs ??= new ReferenceCatalogs($this->root);
        if (! $this->catalogs->enabled()) {
            return;
        }
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
                return;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\FuncCall
            || ! $call->name instanceof Node\Name
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $name = $call->name->toString();
        /** @var mixed $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            $name = $namespaced->toString();
        }
        if (! in_array(strtolower($name), ['view', 'trans', '__', 'trans_choice'], true)) {
            return;
        }
        $function = $context->codebase->getFunction($name);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        if (! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')) {
            return;
        }
        $name = strtolower($name);
        if ($name === 'trans_choice') {
            $this->analyzeChoice($context, $call);

            return;
        }
        $key = null;
        $locale = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name !== null
                && $argument->name->name === ($name === 'view' ? 'view' : 'key')
            ) {
                $key = $argument->value;
            }
            if (
                $argument->name === null
                && $offset === 2
                || $argument->name !== null
                && $argument->name->name === 'locale'
            ) {
                $locale = $argument->value;
            }
        }
        if (! $key instanceof Node\Scalar\String_) {
            return;
        }
        $missing = $name === 'view'
            ? $this->catalogs->missingView($key->value)
            : $locale instanceof Node\Scalar\String_
            && $this->catalogs->missingTranslation($key->value, $locale->value);
        if ($missing) {
            $kind = $name === 'view' ? 'view' : 'translation';
            $issue = Issue::at(
                'Literal '.$kind.' reference "'.$key->value.'" is absent from the explicitly complete catalogs.',
                new SourceLocation($context->source->path, $context->node->span),
            );
            foreach ($name === 'view'
                ? $this->catalogs->viewSuggestionNotes($key->value)
                : (
                    $locale instanceof Node\Scalar\String_
                        ? $this->catalogs->translationLookupNotes($locale->value)
                        : []
                ) as $note) {
                $issue = $issue->withNote($note);
            }
            $context->report(
                Level::Warning,
                'laramago-missing-'.$kind,
                $issue,
            );
        }
    }

    private function analyzeChoice(NodeAnalysisContext $context, Node\Expr\FuncCall $call): void
    {
        $arguments = LaravelReferenceCallRegistry::arguments(LaravelReferenceCallRegistry::TRANSLATION_CHOICE, $call);
        $key = LiteralStringArgument::from($arguments['key'] ?? null);
        $locale = LiteralStringArgument::from($arguments['locale'] ?? null);
        if (
            $key === null
            || $locale === null
            || in_array($locale->value, ['', '0'], true)
            || ! isset($arguments['number'])
        ) {
            return;
        }
        $contract = $this->translationContract ??= new NativeTranslationContract($this->root);
        if (! $contract->choiceHelperMatches($context->codebase)) {
            return;
        }
        $bindings = $this->bindings ??= new ContainerBindings($this->root);
        foreach ([
            'translator',
            'Illuminate\\Translation\\Translator',
            'Illuminate\\Contracts\\Translation\\Translator',
            'translation.loader',
            'Illuminate\\Contracts\\Translation\\Loader',
        ] as $service) {
            if ($bindings->configured($service)) {
                return;
            }
        }
        $catalogs = $this->catalogs ??= new ReferenceCatalogs($this->root);
        if (! $catalogs->missingTranslationChoice($key->value, $locale->value)) {
            return;
        }
        $issue = Issue::at(
            'Literal translation reference "'
            .$key->value
            .'" is absent from the explicitly complete translations catalog.',
            new SourceLocation($context->source->path, $context->node->span),
        );
        foreach ($catalogs->translationChoiceNotes($locale->value) as $note) {
            $issue = $issue->withNote($note);
        }
        $context->report(Level::Warning, 'laramago-missing-translation', $issue);
    }
}
