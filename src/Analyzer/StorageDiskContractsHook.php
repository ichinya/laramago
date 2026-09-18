<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConfigurationIndex;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
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

/** Diagnose missing literal disks only under an explicit closed-runtime contract. */
final class StorageDiskContractsHook implements NodeAnalysisHook
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Storage';
    private const MANAGER = 'Illuminate\\Filesystem\\FilesystemManager';

    private readonly bool $runtimeComplete;
    private readonly NativeFacade $facade;
    private readonly ContainerBindings $bindings;
    private readonly ConfigurationIndex $configuration;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(string $root = '.')
    {
        $this->runtimeComplete = self::runtimeComplete($root);
        $this->facade = new NativeFacade($root);
        $this->bindings = new ContainerBindings($root);
        $this->configuration = new ConfigurationIndex(new PhpSource($root));
    }

    public function getTargets(): array
    {
        return [NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (
            ! $this->runtimeComplete
            || $this->bindings->configured('config')
            || $this->bindings->configured('filesystem')
            || ! $this->facade->dispatchesClass(
                $context->codebase,
                self::FACADE,
                'filesystem',
                self::MANAGER,
                'disk',
            )
        ) {
            return;
        }
        $catalog = $this->configuration->stringKeys('filesystems.disks');
        if ($catalog === null || ! $catalog->sourceComplete) {
            return;
        }
        $call = $this->call($context);
        if ($call === null) {
            return;
        }
        $name = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name !== null
                && $argument->name->name === 'name'
            ) {
                $name = $argument->value;
            }
        }
        if (
            ! $name instanceof Node\Scalar\String_
            || $name->value === ''
            || $name->value === '0'
            || in_array($name->value, $catalog->keys, true)
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-storage-disk',
            Issue::at(
                'Storage disk "'.$name->value.'" is absent from the explicitly complete runtime disk catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\StaticCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\StaticCall
            || ! $call->class instanceof Node\Name
            || strcasecmp($call->class->toString(), self::FACADE) !== 0
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== 'disk'
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        return $call;
    }

    private static function runtimeComplete(string $root): bool
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return false;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $contract */
        $contract = is_array($settings) ? $settings['storage-disks'] ?? null : null;

        return (
            is_array($contract)
            && ($contract['complete'] ?? null) === true
            && ($contract['runtime-disks-unchanged'] ?? null) === true
        );
    }
}
