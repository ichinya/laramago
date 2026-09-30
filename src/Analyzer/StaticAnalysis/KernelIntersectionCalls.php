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

/** Only direct, positive instanceof branches can select a kernel contract. */
final class KernelIntersectionCalls implements CodebaseScanHook
{
    private const MAX_CALLS = 100_000;
    private const KERNELS = ['Illuminate\\Contracts\\Http\\Kernel', 'Illuminate\\Contracts\\Console\\Kernel'];

    /** @var array<string, array{owner: string, method: string}|null> */
    private array $calls = [];
    private bool $complete = false;
    private bool $failed = false;

    public function getTargets(): array
    {
        return ['**'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        $this->complete = false;
        if ($context->firstBatch) {
            $this->calls = [];
            $this->complete = false;
            $this->failed = false;
        }
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            if ($this->failed) {
                break;
            }
            if (strlen($file->contents) > 2_000_000) {
                $this->failed = true;
                break;
            }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) {
                $this->failed = true;
                break;
            }
            $candidates = [];
            $this->inspect($nodes, null, $candidates);
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                $key = $call->getStartFilePos().':'.($call->getEndFilePos() + 1);
                // A noncandidate or a second host file with the same bytes poisons the span.
                $this->calls[$key] = array_key_exists($key, $this->calls) ? null : ($candidates[$key] ?? null);
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

    public function owner(Span $span, string $method): ?string
    {
        $call = $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;

        return $call !== null && strcasecmp($call['method'], $method) === 0 ? $call['owner'] : null;
    }

    /** @param array<array-key, Node>|Node|null $node
     * @param array<string, array{owner: string, method: string}> $candidates */
    private function inspect(array|Node|null $node, ?Node\FunctionLike $scope, array &$candidates): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->inspect($child, $scope, $candidates);
            }
            return;
        }
        if ($node === null) {
            return;
        }
        if ($node instanceof Node\FunctionLike) {
            $scope = $node;
        }
        if ($node instanceof Node\Stmt\If_) {
            $condition = $node->cond;
            if ($condition instanceof Node\Expr\Instanceof_ && $condition->expr instanceof Node\Expr\Variable
                && is_string($condition->expr->name)
                && ! in_array($condition->expr->name, ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true)
                && $condition->class instanceof Node\Name\FullyQualified
                && in_array($condition->class->toString(), self::KERNELS, true)
                && ! $this->exposed($scope ?? $node->stmts, $condition->expr->name)) {
                $this->branch($node->stmts, $condition->expr->name, $condition->class->toString(), $candidates);
            }
        }
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;
            if ($child instanceof Node || is_array($child)) {
                $this->inspect($child, $scope, $candidates);
            }
        }
    }

    /** @param Node\FunctionLike|list<Node\Stmt> $scope */
    private function exposed(Node\FunctionLike|array $scope, string $variable): bool
    {
        return (new NodeFinder)->findFirst(is_array($scope) ? $scope : [$scope], fn (Node $node): bool =>
            ($node instanceof Node\Expr\Variable && ! is_string($node->name))
            || (($node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_
                || $node instanceof Node\Stmt\Static_) && $this->uses($node, $variable))
            || (($node instanceof Node\Param || $node instanceof Node\ClosureUse)
                && $node->byRef && $this->uses($node, $variable))
            || ($node instanceof Node\Stmt\Foreach_ && $node->byRef && $this->uses($node->valueVar, $variable))
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || ($node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true)))
            || ($node instanceof Node\Arg && $this->uses($node, $variable))) !== null;
    }

    /** @param list<Node\Stmt> $statements
     * @param array<string, array{owner: string, method: string}> $candidates */
    private function branch(array $statements, string $variable, string $owner, array &$candidates): void
    {
        foreach ($statements as $statement) {
            if (! $statement instanceof Node\Stmt\Expression && ! $statement instanceof Node\Stmt\Return_) {
                break;
            }
            if ((new NodeFinder)->findFirst([$statement], fn (Node $node): bool =>
                $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike
                || (($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
                    || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec
                    || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec)
                    && $this->uses($node->var, $variable))) !== null) {
                break;
            }
            foreach ((new NodeFinder)->findInstanceOf([$statement], Node\Expr\MethodCall::class) as $call) {
                if (! $call->var instanceof Node\Expr\Variable || $call->var->name !== $variable
                    || ! $call->name instanceof Node\Identifier
                    || ! in_array(strtolower($call->name->name), ['handle', 'terminate'], true)
                    || $call->isFirstClassCallable()) {
                    continue;
                }
                $candidates[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = [
                    'owner' => $owner, 'method' => strtolower($call->name->name),
                ];
            }
        }
    }

    private function uses(Node $node, string $variable): bool
    {
        return (new NodeFinder)->findFirst([$node], static fn (Node $node): bool =>
            $node instanceof Node\Expr\Variable && $node->name === $variable) !== null;
    }
}
