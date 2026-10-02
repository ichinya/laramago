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

/** Recover lexical parameter identity only for an unescaped first-statement reload. */
final class ModelRefreshCalls implements CodebaseScanHook
{
    /** @var array<string, array{file: string, owner: ?string, name: string, scope: Node\Stmt\Function_|Node\Stmt\ClassMethod, parameter: int, call: Node\Expr\MethodCall}|null> */
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
            foreach (self::scopes($nodes) as [$owner, $name, $scope]) {
                $statement = $scope->stmts[0] ?? null;
                $expression = $statement instanceof Node\Stmt\Return_ || $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
                if ($expression instanceof Node\Expr\Assign && $expression->var instanceof Node\Expr\Variable && is_string($expression->var->name)) {
                    $expression = $expression->expr;
                }
                if (! $expression instanceof Node\Expr\MethodCall || ! $expression->name instanceof Node\Identifier
                    || ! in_array(strtolower($expression->name->name), ['fresh', 'refresh'], true) || $expression->args !== []
                    || ! $expression->var instanceof Node\Expr\Variable || ! is_string($expression->var->name)
                    || ! self::safeScope($scope, $expression->var->name)) {
                    continue;
                }
                foreach ($scope->params as $index => $parameter) {
                    if ($parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $expression->var->name
                        && ! $parameter->byRef && ! $parameter->variadic) {
                        $candidates[self::key($expression)] = ['file' => $file->path, 'owner' => $owner, 'name' => $name,
                            'scope' => $scope, 'parameter' => $index, 'call' => $expression];
                    }
                }
            }
            // The SDK does not identify the source file in a method invocation.
            // Every host call, including unrelated calls, participates in collision detection.
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

    /** @return array{file: string, owner: ?string, name: string, scope: Node\Stmt\Function_|Node\Stmt\ClassMethod, parameter: int, call: Node\Expr\MethodCall}|null */
    public function call(Span $span): ?array
    {
        return $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
    }

    /** @param array<Node> $nodes
     * @return iterable<array{?string, string, Node\Stmt\Function_|Node\Stmt\ClassMethod}> */
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::scopes($node->stmts);
            } elseif ($node instanceof Node\Stmt\Function_) {
                yield [null, $node->namespacedName->toString(), $node];
            } elseif ($node instanceof Node\Stmt\Class_ && $node->name !== null && $node->namespacedName !== null) {
                foreach ($node->getMethods() as $method) {
                    yield [$node->namespacedName->toString(), $method->name->name, $method];
                }
            }
        }
    }

    private static function safeScope(Node\FunctionLike $scope, string $parameter): bool
    {
        if ($scope->returnsByRef() || $parameter === 'this' || str_starts_with($parameter, '_') || $parameter === 'GLOBALS') {
            return false;
        }
        $finder = new NodeFinder;
        $unsafe = $finder->findFirst([$scope], static fn (Node $node): bool =>
            $node !== $scope && ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike)
            || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Param && $node->byRef || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name === 'GLOBALS')
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true)));
        // The declaration and receiver are the only uses. No write, passing by
        // reference, capture, alias, or hidden callable-local mutation is admitted.
        return $unsafe === null && count($finder->find([$scope], static fn (Node $node): bool =>
            $node instanceof Node\Expr\Variable && $node->name === $parameter)) === 2;
    }

    private static function key(Node $node): string
    {
        return $node->getStartFilePos().':'.($node->getEndFilePos() + 1);
    }
}
