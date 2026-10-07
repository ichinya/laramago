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

/** Index a repeated getter in the next statement after a successful scalar assertion. */
final class AssertedPureGetterCalls implements CodebaseScanHook
{
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header'];
    /** @var array<string, array<string, mixed>|null> */
    private array $calls = [];
    /** @var array<string, string> */
    private array $sources = [];
    private bool $complete = false;
    private bool $failed = false;
    private bool $started = false;
    private int $bytes = 0;
    public int $generation = 0;

    public function __construct(private readonly string $root = '.') {}

    public function getTargets(): array { return ['**']; }

    public function reset(): void
    {
        $this->generation++;
        $this->calls = $this->sources = [];
        $this->complete = $this->failed = $this->started = false;
        $this->bytes = 0;
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->reset(); $this->started = true; }
        $this->complete = false;
        if (! $this->started) { $this->failed = true; }
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            if ($this->failed) { break; }
            $path = $this->path($file->path);
            $size = strlen($file->contents);
            $this->bytes += $size;
            if ($size > 2_000_000 || $this->bytes > 64 * 1024 * 1024 || isset($this->sources[$path])
                || count($this->sources) >= 100_000) { $this->failed = true; break; }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $this->sources[$path] = hash('sha256', $file->contents);
            $candidates = [];
            $finder = new NodeFinder;
            $ticks = $finder->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Declare_
                && array_filter($node->declares, static fn ($directive): bool => strtolower($directive->key->name) !== 'strict_types') !== []) !== null;
            if (! $ticks) {
                foreach (self::scopes($nodes) as [$owner, $name, $scope]) {
                    if ($scope->byRef || $finder->findFirst([$scope], static fn (Node $node): bool =>
                        $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label || $node instanceof Node\Stmt\Global_
                        || $node instanceof Node\Param && $node->byRef) !== null) { continue; }
                    foreach (self::lists($scope->stmts ?? []) as $statements) {
                        for ($index = 1; $index < count($statements); $index++) {
                            $assertion = $statements[$index - 1] instanceof Node\Stmt\Expression ? $statements[$index - 1]->expr : null;
                            $observed = self::assertionGetter($assertion);
                            if ($observed === null) { continue; }
                            $next = $statements[$index];
                            $expression = $next instanceof Node\Stmt\Expression ? $next->expr : ($next instanceof Node\Stmt\Return_ ? $next->expr : null);
                            $getter = $expression === null ? null : self::firstGetter($expression, $observed->var->name);
                            if ($getter === null || $getter->var->name !== $observed->var->name
                                || strcasecmp($getter->name->name, $observed->name->name) !== 0) { continue; }
                            $candidates[self::key($getter)] = compact('owner', 'name', 'scope', 'assertion', 'observed', 'getter') + ['file' => $file->path];
                        }
                    }
                }
            }
            // The SDK invocation span is fileless: every unrelated call participates in collisions.
            foreach ($finder->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                $key = self::key($call);
                $this->calls[$key] = array_key_exists($key, $this->calls) ? null : ($candidates[$key] ?? null);
                if (count($this->calls) > 100_000) { $this->failed = true; break; }
            }
        }
        if ($this->failed) { $this->calls = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    /** @return array<string, mixed>|null */
    public function call(Span $span): ?array
    {
        $candidate = $this->complete ? ($this->calls[$span->start.':'.$span->end] ?? null) : null;
        return $candidate !== null && $this->sourceMatches($candidate['file']) ? $candidate : null;
    }

    public function sourceMatches(string $file, bool $required = true): bool
    {
        $path = $this->path($file);
        return array_key_exists($path, $this->sources)
            ? $this->sources[$path] === @hash_file('sha256', $path)
            : ! $required;
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

    /** @param array<Node> $nodes @return iterable<array{?string, string, Node\Stmt\Function_|Node\Stmt\ClassMethod}> */
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

    /** @param list<Node\Stmt> $statements @return iterable<list<Node\Stmt>> */
    private static function lists(array $statements, int $depth = 0): iterable
    {
        if ($depth > 32) { return; }
        yield $statements;
        foreach ($statements as $statement) {
            if ($statement instanceof Node\FunctionLike || $statement instanceof Node\Stmt\ClassLike) { continue; }
            foreach ($statement->getSubNodeNames() as $key) {
                $value = $statement->$key;
                if ($key === 'stmts' && is_array($value)) { yield from self::lists($value, $depth + 1); }
                elseif ($value instanceof Node\Stmt) { yield from self::lists([$value], $depth + 1); }
                elseif (is_array($value)) {
                    foreach ($value as $child) { if ($child instanceof Node\Stmt) { yield from self::lists([$child], $depth + 1); } }
                }
            }
        }
    }

    private static function assertionGetter(?Node\Expr $expression): ?Node\Expr\MethodCall
    {
        if (! $expression instanceof Node\Expr\StaticCall || ! $expression->class instanceof Node\Name
            || strcasecmp($expression->class->toString(), 'Testo\\Assert') !== 0 || ! $expression->name instanceof Node\Identifier
            || strcasecmp($expression->name->name, 'notNull') !== 0 || count($expression->args) < 1 || count($expression->args) > 2) { return null; }
        $observed = null;
        foreach ($expression->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack) { return null; }
            $name = $argument->name?->name ?? ($index === 0 ? 'actual' : 'message');
            if ($name === 'actual' && $observed === null && self::getter($argument->value)) { $observed = $argument->value; }
            elseif ($name !== 'message' || ! $argument->value instanceof Node\Scalar\String_) { return null; }
        }
        return $observed;
    }

    private static function firstGetter(Node\Expr $expression, string $receiver, int $depth = 0): ?Node\Expr\MethodCall
    {
        if ($depth > 8) { return null; }
        if (self::getter($expression)) { return $expression; }
        if ($expression instanceof Node\Expr\Assign && $expression->var instanceof Node\Expr\Variable
            && is_string($expression->var->name) && $expression->var->name !== $receiver
            && ! in_array($expression->var->name, self::RESERVED, true)) {
            return self::firstGetter($expression->expr, $receiver, $depth + 1);
        }
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name) {
            $argument = $expression->args[0] ?? null;
            return $argument instanceof Node\Arg && ! $argument->byRef && ! $argument->unpack && $argument->name === null
                ? self::firstGetter($argument->value, $receiver, $depth + 1) : null;
        }
        return null;
    }

    private static function getter(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier
            && $expression->args === [] && $expression->var instanceof Node\Expr\Variable
            && is_string($expression->var->name) && ! in_array($expression->var->name, self::RESERVED, true);
    }

    private static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }
}
