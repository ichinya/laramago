<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Match a provider's fileless span only when every host source agrees on its identity. */
final class AggregateProjectionCalls implements CodebaseScanHook
{
    private const MAX_CALLS = 100_000;

    /** @var array<string, Node\Expr\MethodCall|null> */
    private array $calls = [];
    private bool $complete = false;
    private bool $failed = false;

    public function getTargets(): array
    {
        // Every analyzed source is relevant: another file can contain the same byte span.
        return ['**'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->calls = [];
            $this->failed = false;
            $this->complete = false;
        }
        foreach ($context->files as $file) {
            if ($this->failed) {
                break;
            }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) {
                $this->failed = true;
                break;
            }
            foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool =>
                $node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall) as $call) {
                $key = $call->getStartFilePos().':'.($call->getEndFilePos() + 1);
                if (array_key_exists($key, $this->calls)) {
                    // A collision is uncertain even when one of the calls is not a projection.
                    $this->calls[$key] = null;
                } else {
                    $this->calls[$key] = $call instanceof Node\Expr\MethodCall
                        && $call->name instanceof Node\Identifier
                        && strcasecmp($call->name->name, 'get') === 0
                        ? $call : null;
                }
                if (count($this->calls) > self::MAX_CALLS) {
                    $this->failed = true;
                    break;
                }
            }
        }
        if ($this->failed) {
            $this->calls = [];
        }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    public function call(Span $span): ?Node\Expr\MethodCall
    {
        return $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
    }
}
