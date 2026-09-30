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

/** Index consecutive assertion statements without assuming a provider span has a filename. */
final class ArrayAssertionCalls implements CodebaseScanHook
{
    /** @var array<string, list<Node\Expr>|null> */
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
            $this->complete = false;
            $this->failed = false;
        }
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
            $runs = [];
            $this->collect($nodes, $runs);
            foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool =>
                $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\FuncCall) as $call) {
                $key = $call->getStartFilePos().':'.($call->getEndFilePos() + 1);
                $this->calls[$key] = array_key_exists($key, $this->calls) ? null : ($runs[$key] ?? null);
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

    /** @return list<Node\Expr>|null */
    public function run(Span $span): ?array
    {
        return $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
    }

    /** @param array<array-key, mixed> $nodes
     * @param array<string, list<Node\Expr>> $runs
     */
    private function collect(array $nodes, array &$runs): void
    {
        $run = [];
        foreach ($nodes as $node) {
            if (! $node instanceof Node) {
                $run = [];
                continue;
            }
            if ($node instanceof Node\FunctionLike && ! self::unescapedScope($node)) {
                $run = [];
                continue;
            }
            if ($node instanceof Node\Stmt\Expression && self::assertion($node->expr) !== null && count($run) < 64) {
                $run[] = $node->expr;
                $call = $node->expr;
                $runs[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $run;
            } else {
                $run = [];
            }
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->$name;
                if (is_array($child)) {
                    $this->collect($child, $runs);
                } elseif ($child instanceof Node) {
                    $this->collect([$child], $runs);
                }
            }
        }
    }

    private static function unescapedScope(Node\FunctionLike $scope): bool
    {
        if ($scope->returnsByRef()) {
            return false;
        }
        return (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_
            || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Param && $node->byRef || $node instanceof Node\ClosureUse && $node->byRef
            || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name === 'GLOBALS')
            || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true)) === null;
    }

    public static function assertion(Node\Expr $expression): ?Node\Expr\StaticCall
    {
        if ($expression instanceof Node\Expr\MethodCall) {
            if (! $expression->name instanceof Node\Identifier
                || ! in_array(strtolower($expression->name->name), ['haskeys', 'doesnothavekeys'], true)
                || ! $expression->var instanceof Node\Expr\StaticCall) {
                return null;
            }
            foreach ($expression->args as $arg) {
                if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name !== null
                    || (! $arg->value instanceof Node\Scalar\String_ && ! $arg->value instanceof Node\Scalar\Int_)) {
                    return null;
                }
            }
            $expression = $expression->var;
        }
        if (! $expression instanceof Node\Expr\StaticCall || ! $expression->class instanceof Node\Name\FullyQualified
            || ! $expression->name instanceof Node\Identifier) {
            return null;
        }
        $identity = strtolower($expression->class->toString().'::'.$expression->name->name);
        return in_array($identity, ['laratesto\\testing\\phpunitcompatibility::assertisarray', 'testo\\assert::array'], true)
            ? $expression : null;
    }

    /** @return list<string>|null A local variable followed by constant array keys. */
    public static function path(Node\Expr $expression): ?array
    {
        $keys = [];
        while ($expression instanceof Node\Expr\ArrayDimFetch && count($keys) < 8) {
            $key = $expression->dim;
            if (! $key instanceof Node\Scalar\String_ && ! $key instanceof Node\Scalar\Int_) {
                return null;
            }
            array_unshift($keys, json_encode($key->value, JSON_THROW_ON_ERROR));
            $expression = $expression->var;
        }
        if (! $expression instanceof Node\Expr\Variable || ! is_string($expression->name)
            || in_array($expression->name, ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true)) {
            return null;
        }
        array_unshift($keys, '$'.$expression->name);
        return $keys;
    }
}
