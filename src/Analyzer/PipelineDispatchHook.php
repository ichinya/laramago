<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Checks the first, directly constructed pipe in an immediate native Pipeline chain. */
final class PipelineDispatchHook implements MethodCallAnalysisHook
{
    private const PIPELINE = 'Illuminate\\Pipeline\\Pipeline';

    public function __construct(
        private readonly string $projectRoot = '.',
    ) {}

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::PIPELINE, 'thenReturn')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($context->source->contents);
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
        } catch (\PhpParser\Error) {
            return;
        }
        $call = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Expr\MethodCall
                && $node->getStartFilePos() === $context->node->span->start
                && ($node->getEndFilePos() + 1) === $context->node->span->end
            ),
        );
        if (! $call instanceof Node\Expr\MethodCall || ! self::call($call, 'thenReturn', 0)) {
            return;
        }
        $through = $call->var;
        if (! $through instanceof Node\Expr\MethodCall || ! self::call($through, 'through', 1)) {
            return;
        }
        $send = $through->var;
        if (! $send instanceof Node\Expr\MethodCall || ! self::call($send, 'send', 1)) {
            return;
        }
        $pipeline = $send->var;
        if (
            ! $pipeline instanceof Node\Expr\New_
            || ! $pipeline->class instanceof Node\Name
            || strcasecmp($pipeline->class->toString(), self::PIPELINE) !== 0
            || $pipeline->args !== []
        ) {
            return;
        }
        $pipes = $through->getArgs()[0]->value;
        if (! $pipes instanceof Node\Expr\Array_ || $pipes->items === []) {
            return;
        }
        foreach ($pipes->items as $item) {
            if ($item->key !== null || $item->unpack || $item->byRef) {
                return;
            }
        }
        $pipe = $pipes->items[0]->value;
        if (
            ! $pipe instanceof Node\Expr\New_
            || ! $pipe->class instanceof Node\Name
            || $pipe->class->isSpecialClassName()
            || $pipe->args !== []
        ) {
            return;
        }
        $class = $context->codebase->getClass($pipe->class->toString());
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || $class->flags->contains(MetadataFlags::ABSTRACT)
            || $class->directParentClass !== null
            || $class->usedTraits !== []
            || $class->pseudoMethods !== []
            || $class->staticPseudoMethods !== []
            || $class->mixins !== []
        ) {
            return;
        }
        foreach ($class->methods as $method) {
            if (in_array(strtolower($method), ['handle', '__invoke', '__call', '__callstatic', '__construct'], true)) {
                return;
            }
        }
        if (! (new StaticAnalysis\NativePipelineContract($this->projectRoot))->matches($context->codebase)) {
            return;
        }
        $context->report(Level::Warning, 'laramago-missing-pipeline-dispatch', Issue::at(
            'The first pipeline object '.$class->name.' has neither handle nor __invoke.',
            new SourceLocation($context->source->path, new Span($pipe->getStartFilePos(), $pipe->getEndFilePos() + 1)),
        ));
    }

    private static function call(Node\Expr\MethodCall $call, string $name, int $count): bool
    {
        if (
            ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->name, $name) !== 0
            || $call->isFirstClassCallable()
            || count($call->getArgs()) !== $count
        ) {
            return false;
        }
        foreach ($call->getArgs() as $arg) {
            if ($arg->name !== null || $arg->unpack) {
                return false;
            }
        }

        return true;
    }
}
