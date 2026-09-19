<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\NodeAnalysisContext;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Finds only the first direct object in an immediate default Pipeline execution. */
final class ImmediatePipelinePipe
{
    private const PIPELINE = 'Illuminate\\Pipeline\\Pipeline';

    public static function find(NodeAnalysisContext $context): ?Node\Expr\New_
    {
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($context->source->contents);
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
        } catch (\PhpParser\Error) {
            return null;
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
            return null;
        }
        $through = $call->var;
        if (! $through instanceof Node\Expr\MethodCall || ! self::call($through, 'through', 1)) {
            return null;
        }
        $send = $through->var;
        if (! $send instanceof Node\Expr\MethodCall || ! self::call($send, 'send', 1)) {
            return null;
        }
        $pipeline = $send->var;
        if (
            ! $pipeline instanceof Node\Expr\New_
            || ! $pipeline->class instanceof Node\Name
            || strcasecmp($pipeline->class->toString(), self::PIPELINE) !== 0
            || $pipeline->args !== []
        ) {
            return null;
        }
        $pipes = $through->getArgs()[0]->value;
        if (! $pipes instanceof Node\Expr\Array_ || $pipes->items === []) {
            return null;
        }
        foreach ($pipes->items as $item) {
            if ($item->key !== null || $item->unpack || $item->byRef) {
                return null;
            }
        }
        $pipe = $pipes->items[0]->value;
        if (
            ! $pipe instanceof Node\Expr\New_
            || ! $pipe->class instanceof Node\Name
            || $pipe->class->isSpecialClassName()
            || $pipe->args !== []
        ) {
            return null;
        }

        return $pipe;
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
