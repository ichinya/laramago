<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\MixManifestCatalog;
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

/** Warn at an analyzed Mix call when its explicitly selected manifest cannot be cataloged. */
final class MixManifestParseHook implements NodeAnalysisHook, InitializationHook
{
    private ?MixManifestCatalog $catalog = null;
    private ?MixManifestReferencesHook $references = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = null;
        $this->references = null;
        $this->sourceHash = null;
        $this->calls = [];
    }

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
        $call = $this->call($context);
        if ($call === null) {
            return;
        }
        $this->references ??= new MixManifestReferencesHook($this->root);
        if (! $this->references->isNativeCall($call, $context)) {
            return;
        }
        [$path, $directory] = $this->references->literalArguments($call);
        if (
            ! $path instanceof Node\Scalar\String_
            || $path->value === ''
            || ! $directory instanceof Node\Scalar\String_
        ) {
            return;
        }
        $this->catalog ??= new MixManifestCatalog($this->root);
        $selectedDirectory = $directory->value;
        $manifest = $this->catalog->manifestPath($selectedDirectory);
        if ($manifest === null || $this->catalog->hotFileState($selectedDirectory) !== 'absent') {
            return;
        }
        $status = $this->catalog->status($selectedDirectory);
        if ($status !== 'invalid-json' && $status !== 'invalid-shape') {
            return;
        }
        $message = $status === 'invalid-json'
            ? 'Configured Mix manifest "'.$manifest.'" contains malformed JSON.'
            : 'Configured Mix manifest "'.$manifest.'" has an unsupported JSON shape for static indexing.';
        $context->report(
            Level::Warning,
            'laramago-mix-manifest-'.$status,
            Issue::at($message, new SourceLocation($context->source->path, $context->node->span))
                ->withNote(
                    'This warning describes the configured manifest snapshot; it does not assert a runtime exception.',
                ),
        );
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\FuncCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;

        return $call instanceof Node\Expr\FuncCall
        && $call->name instanceof Node\Name
        && ! $call->isFirstClassCallable()
            ? $call
            : null;
    }
}
