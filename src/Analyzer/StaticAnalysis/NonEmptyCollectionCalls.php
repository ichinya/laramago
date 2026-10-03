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

/** Index an immediate nonempty branch for a freshly allocated eager collection. */
final class NonEmptyCollectionCalls implements CodebaseScanHook
{
    private const CLASSES = ['Illuminate\\Support\\Collection', 'Illuminate\\Database\\Eloquent\\Collection'];
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header'];
    /** @var array<string, array{file: string, owner: ?string, name: string, scope: Node\Stmt\Function_|Node\Stmt\ClassMethod, call: Node\Expr\MethodCall, origin: Node\Expr\New_}|null> */
    private array $calls = [];
    /** @var array<string, string|null> */
    private array $sources = [];
    private bool $complete = false;
    private bool $failed = false;
    public int $generation = 0;

    public function __construct(private readonly string $root = '.') {}

    public function getTargets(): array { return ['**']; }

    public function reset(): void
    {
        $this->generation++;
        $this->calls = [];
        $this->sources = [];
        $this->complete = false;
        $this->failed = false;
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->reset();
        }
        $this->complete = false;
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            if ($this->failed) { break; }
            try {
                if (strlen($file->contents) > 2_000_000) { $this->failed = true; break; }
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $path = $this->path($file->path);
            $this->sources[$path] = array_key_exists($path, $this->sources) ? null : hash('sha256', $file->contents);
            $candidates = [];
            foreach (self::scopes($nodes) as [$owner, $name, $scope]) {
                foreach (self::candidates($scope) as [$call, $origin]) {
                    $candidates[self::key($call)] = ['file' => $file->path, 'owner' => $owner, 'name' => $name,
                        'scope' => $scope, 'call' => $call, 'origin' => $origin];
                }
            }
            // Invocations have no file identity. Even an unrelated call poisons a shared span.
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                $key = self::key($call);
                $this->calls[$key] = array_key_exists($key, $this->calls) ? null : ($candidates[$key] ?? null);
                if (count($this->calls) > 100_000 || count($this->sources) > 100_000) { $this->failed = true; break; }
            }
        }
        if ($this->failed) { $this->calls = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    /** @return array{file: string, owner: ?string, name: string, scope: Node\Stmt\Function_|Node\Stmt\ClassMethod, call: Node\Expr\MethodCall, origin: Node\Expr\New_}|null */
    public function call(Span $span): ?array
    {
        $candidate = $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
        return $candidate !== null && $this->sourceMatches($candidate['file']) ? $candidate : null;
    }

    /** Missing dependency snapshots retain the separately audited installed source contract. */
    public function sourceMatches(string $file): bool
    {
        $path = $this->path($file);
        return ! array_key_exists($path, $this->sources)
            || $this->sources[$path] !== null && $this->sources[$path] === @hash_file('sha256', $path);
    }

    public function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $path) === 1) { $path = substr($path, 4); }
        if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) {
            $path = str_replace('\\', '/', $this->root).'/'.$path;
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /** @param array<Node> $nodes
     * @return iterable<array{?string, string, Node\Stmt\Function_|Node\Stmt\ClassMethod}> */
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { yield from self::scopes($node->stmts); }
            elseif ($node instanceof Node\Stmt\Function_) { yield [null, $node->namespacedName->toString(), $node]; }
            elseif ($node instanceof Node\Stmt\Class_ && $node->name !== null && $node->namespacedName !== null) {
                foreach ($node->getMethods() as $method) { yield [$node->namespacedName->toString(), $method->name->name, $method]; }
            }
        }
    }

    /** @return iterable<array{Node\Expr\MethodCall, Node\Expr\New_}> */
    private static function candidates(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope): iterable
    {
        $finder = new NodeFinder;
        if ($scope->byRef || $finder->findFirst([$scope], static fn (Node $node): bool =>
            $node !== $scope && ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike)
            || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Param && $node->byRef || $node instanceof Node\Arg && $node->byRef
            || $node instanceof Node\ArrayItem && $node->byRef || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name === 'GLOBALS')
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str', 'compact', 'get_defined_vars'], true))) !== null) { return; }
        foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Expr\Ternary::class) as $ternary) {
            $guard = $ternary->cond;
            if (! $guard instanceof Node\Expr\MethodCall || ! $guard->name instanceof Node\Identifier
                || strtolower($guard->name->name) !== 'isempty' || $guard->args !== []
                || ! $guard->var instanceof Node\Expr\Variable || ! is_string($guard->var->name)
                || in_array($guard->var->name, self::RESERVED, true)) { continue; }
            $call = self::firstEvaluation($ternary->else);
            if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier
                || ! in_array(strtolower($call->name->name), ['first', 'last'], true) || $call->args !== []
                || ! $call->var instanceof Node\Expr\Variable || $call->var->name !== $guard->var->name) { continue; }
            $local = $guard->var->name;
            $origin = null;
            foreach ($scope->stmts ?? [] as $statement) {
                if ($statement->getStartFilePos() >= $ternary->getStartFilePos()) { break; }
                $assignment = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
                if ($assignment instanceof Node\Expr\Assign && $assignment->var instanceof Node\Expr\Variable
                    && $assignment->var->name === $local && $assignment->expr instanceof Node\Expr\New_
                    && $assignment->expr->class instanceof Node\Name
                    && in_array($assignment->expr->class->toString(), self::CLASSES, true)
                    && count($assignment->expr->args) === 1) {
                    $argument = $assignment->expr->args[0];
                    if ($argument instanceof Node\Arg && ! $argument->byRef && ! $argument->unpack
                        && ($argument->name === null || $argument->name->name === 'items')
                        && $argument->value instanceof Node\Expr\Array_
                        && $finder->findFirst([$argument->value], static fn (Node $node): bool => $node instanceof Node\ArrayItem && ($node->byRef || $node->unpack)) === null) {
                        $origin = $assignment->expr;
                    }
                }
            }
            // Exactly assignment, guard and getter: no older binding, alias, capture or escape.
            if ($origin !== null && count($finder->find([$scope], static fn (Node $node): bool =>
                $node instanceof Node\Expr\Variable && $node->name === $local)) === 3) { yield [$call, $origin]; }
        }
    }

    private static function firstEvaluation(Node\Expr $expression): ?Node\Expr\MethodCall
    {
        if ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier
            && $expression->args === []) { return $expression; }
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name
            || $expression instanceof Node\Expr\MethodCall && $expression->var instanceof Node\Expr\Variable && $expression->name instanceof Node\Identifier
            || $expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier) {
            $argument = $expression->args[0] ?? null;
            return $argument instanceof Node\Arg && ! $argument->byRef && ! $argument->unpack && $argument->value instanceof Node\Expr\MethodCall
                ? $argument->value : null;
        }
        return null;
    }

    private static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }
}
