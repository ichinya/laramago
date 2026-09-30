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

/** Index adjacent validation/guard pairs; a fileless span must identify exactly one host call. */
final class ValidatedIntegerCalls implements CodebaseScanHook
{
    /** @var array<string, array{guard: Node\Expr\FuncCall, filter: Node\Expr\FuncCall}|null> */
    private array $calls = [];
    private bool $complete = false;
    private bool $failed = false;

    public function getTargets(): array
    {
        return ['**'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->calls = [];
            $this->failed = false;
        }
        $this->complete = false;
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            if ($this->failed) {
                break;
            }
            try {
                if (strlen($file->contents) > 2_000_000) {
                    $this->failed = true;
                    break;
                }
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) {
                $this->failed = true;
                break;
            }
            $candidates = [];
            foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\FunctionLike) as $scope) {
                if (! self::safeScope($scope)) {
                    continue;
                }
                $statements = $scope->getStmts() ?? [];
                foreach ($statements as $index => $statement) {
                    $previous = $statements[$index - 1] ?? null;
                    $assign = $previous instanceof Node\Stmt\Expression ? $previous->expr : null;
                    $condition = $statement instanceof Node\Stmt\If_ ? $statement->cond : null;
                    $guard = $condition instanceof Node\Expr\BooleanNot ? $condition->expr : $condition;
                    if (! $assign instanceof Node\Expr\Assign || ! $assign->var instanceof Node\Expr\Variable
                        || ! is_string($assign->var->name) || ! self::local($assign->var->name)
                        || ! $assign->expr instanceof Node\Expr\FuncCall || ! self::plainArguments($assign->expr, 3)
                        || ! $guard instanceof Node\Expr\FuncCall || ! self::plainArguments($guard, 1)
                        || ! $guard->args[0]->value instanceof Node\Expr\Variable
                        || $guard->args[0]->value->name !== $assign->var->name) {
                        continue;
                    }
                    // Parameters, captures, earlier writes, and even RHS reads
                    // mean this is not a newly introduced local value.
                    $earlier = (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
                        $node instanceof Node\Expr\Variable && $node !== $assign->var
                        && $node->name === $assign->var->name && $node->getStartFilePos() < $guard->getStartFilePos());
                    if ($earlier === null) {
                        $candidates[self::key($guard)] = ['guard' => $guard, 'filter' => $assign->expr];
                    }
                }
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                $key = self::key($call);
                $this->calls[$key] = array_key_exists($key, $this->calls) ? null : ($candidates[$key] ?? null);
                if (count($this->calls) > 100_000) {
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

    /** @return array{guard: Node\Expr\FuncCall, filter: Node\Expr\FuncCall}|null */
    public function call(Span $span): ?array
    {
        return $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
    }

    private static function safeScope(Node\FunctionLike $scope): bool
    {
        if ($scope->returnsByRef()) {
            return false;
        }
        return (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Param && $node->byRef || $node instanceof Node\ClosureUse && $node->byRef
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name === 'GLOBALS')
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true))) === null;
    }

    private static function plainArguments(Node\Expr\FuncCall $call, int $count): bool
    {
        if (! $call->name instanceof Node\Name || count($call->args) !== $count) {
            return false;
        }
        foreach ($call->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->unpack || $argument->byRef) {
                return false;
            }
        }
        return true;
    }

    private static function local(string $name): bool
    {
        return ! in_array($name, ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true);
    }

    private static function key(Node $node): string
    {
        return $node->getStartFilePos().':'.($node->getEndFilePos() + 1);
    }
}
