<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Span;
use PhpParser\Node;

/** Match a provider's fileless span only when every host source agrees on its identity. */
final class AggregateProjectionCalls implements CodebaseScanHook
{
    private const MAX_CALLS = 100_000;

    /** @var array<string, Node\Expr\MethodCall|null> */
    private array $calls = [];
    private bool $complete = false;
    private bool $failed = false;

    /** @internal Register only from the active owning provider. */
    public static function registerProjection(): void
    {
        StoredCallbackSourceDeclarations::registerSourceIndex();
        CodebaseScanSyntax::registerProjection(self::class.'::get-calls:v1', self::getCalls(...));
    }

    private static function getCalls(array $nodes): array
    {
        $calls = [];
        foreach (CodebaseScanSyntax::find($nodes, static fn (Node $node): bool =>
            $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
            && strcasecmp($node->name->name, 'get') === 0) as $call) {
            $calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            if (count($calls) > self::MAX_CALLS) { break; }
        }
        return $calls;
    }

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
                $calls = CodebaseScanSyntax::query($file->contents, self::class.'::get-calls:v1', self::getCalls(...));
                $reservations = StoredCallbackSourceDeclarations::sourceIndexSnapshot($file->contents)['dispatchReservations'];
            } catch (\PhpParser\Error) {
                $this->failed = true;
                break;
            }
            foreach ($reservations as $key => $duplicate) {
                // Unsupported dispatches and every repeated span remain uncertain.
                $this->calls[$key] = $duplicate || array_key_exists($key, $this->calls)
                    ? null : ($calls[$key] ?? null);
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
