<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\Invocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Unique, current source provenance for an inline transaction forwarding a formal. */
final class ForwardedTransactionTemplateCalls implements CodebaseScanHook
{
    /** @var array<string, array<string, mixed>|null> */
    private array $calls = [];
    /** @var array<string, string> */
    private array $sources = [];
    private bool $started = false;
    private bool $complete = false;
    private bool $failed = false;
    private bool $macroReplacement = false;
    private bool $connectionExtensions = false;
    private int $bytes = 0;
    public int $generation = 0;

    public function __construct(public readonly string $root = '.') {}
    public function getTargets(): array { return ['**']; }

    public function reset(): void
    {
        $this->generation++;
        $this->calls = $this->sources = [];
        $this->started = $this->complete = $this->failed = false;
        $this->bytes = 0;
        $this->macroReplacement = false;
        $this->connectionExtensions = false;
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->reset(); $this->started = true; }
        $this->complete = false;
        if (! $this->started) { $this->failed = true; return; }
        try {
            foreach ($context->files as $file) {
                $context->cancellation->throwIfCancelled();
                if ($this->failed) { break; }
                $path = $this->path($file->path);
                $this->bytes += strlen($file->contents);
                if (strlen($file->contents) > 2_000_000 || $this->bytes > 67_108_864 || count($this->sources) >= 100_000
                    || array_key_exists($path, $this->sources)) { $this->failed = true; break; }
                $this->sources[$path] = hash('sha256', $file->contents);
                try {
                    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                    $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
                } catch (\PhpParser\Error) { $this->failed = true; break; }
                // A framework-default dispatch is supplemented only without visible replacements.
                foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $dispatch) {
                    if (! $dispatch->class instanceof Node\Name || ! in_array(strtolower($dispatch->class->toString()), [
                        'illuminate\\support\\facades\\db', 'illuminate\\database\\databasemanager', 'illuminate\\database\\connection',
                    ], true)) { continue; }
                    if ($dispatch->name instanceof Node\Identifier && strtolower($dispatch->name->name) === 'extend') {
                        $this->connectionExtensions = true;
                    }
                    if (! $dispatch->name instanceof Node\Identifier || in_array(strtolower($dispatch->name->name), [
                        'macro', 'mixin', 'swap', 'setfacadeapplication', 'shouldreceive', 'expects', 'spy', 'partialmock',
                    ], true)) { $this->macroReplacement = true; }
                }
                foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $dispatch) {
                    if ($dispatch->name instanceof Node\Identifier && in_array(strtolower($dispatch->name->name), ['macro', 'mixin'], true)) {
                        $this->macroReplacement = true;
                    }
                }
                $eligible = [];
                foreach (self::scopes($nodes) as [$owner, $scope]) {
                    foreach (self::candidates($scope) as $proof) {
                        $proof += ['owner' => $owner, 'scope' => $scope, 'file' => $path];
                        $eligible[self::key($proof['call'])] = $proof;
                    }
                }
                // Invocation contexts have no file. Incompatible calls reserve the same spans too.
                foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool =>
                    $node instanceof Node\Expr\CallLike) as $call) {
                    $context->cancellation->throwIfCancelled();
                    if (count($this->calls) >= 100_000) { $this->failed = true; break; }
                    $key = self::key($call);
                    $this->calls[$key] = array_key_exists($key, $this->calls) ? null : ($eligible[$key] ?? null);
                }
            }
        } catch (\Throwable $error) { $this->failed = true; $this->complete = false; $this->calls = []; throw $error; }
        if ($this->failed) { $this->calls = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    /** @return array<string, mixed>|null */
    public function proof(Invocation $call): ?array
    {
        $proof = $this->complete ? ($this->calls[$call->span->start.':'.$call->span->end] ?? null) : null;
        return $proof !== null && $this->sourceMatches($proof['file']) ? $proof : null;
    }

    public function sourceMatches(string $file): bool
    {
        $path = $this->path($file);
        return isset($this->sources[$path]) && $this->sources[$path] === @hash_file('sha256', $path);
    }

    /** Offline default macro policy; it does not inspect or execute runtime registrations. */
    public function hasConnectionExtensions(): bool
    {
        return $this->connectionExtensions;
    }

    public function defaultMacroDispatch(): bool
    {
        if (! $this->complete || $this->failed || $this->macroReplacement) { return false; }
        foreach ($this->sources as $file => $hash) { if ($hash !== @hash_file('sha256', $file)) { return false; } }
        return true;
    }

    public function declarationCurrent(string $file): bool
    {
        $path = $this->path($file);
        return ! isset($this->sources[$path]) || $this->sourceMatches($file);
    }

    public function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) { $file = substr($file, 4); }
        if (! str_starts_with($file, '/') && preg_match('~^[A-Za-z]:/~', $file) !== 1) { $file = $this->root.'/'.$file; }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }

    private static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }

    /** @param array<Node> $nodes @return iterable<array{?string, Node\Stmt\ClassMethod|Node\Stmt\Function_}> */
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { yield from self::scopes($node->stmts); }
            elseif ($node instanceof Node\Stmt\Function_) { yield [null, $node]; }
            elseif ($node instanceof Node\Stmt\Class_ && $node->name !== null && $node->namespacedName !== null) {
                foreach ($node->getMethods() as $method) { yield [$node->namespacedName->toString(), $method]; }
            }
        }
    }

    /** @return iterable<array<string, mixed>> */
    private static function candidates(Node\Stmt\ClassMethod|Node\Stmt\Function_ $scope): iterable
    {
        if ($scope->byRef) { return; }
        foreach ($scope->params as $parameter) { if ($parameter->byRef) { return; } }
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || $call->class->toString() !== 'Illuminate\\Support\\Facades\\DB'
                || ! $call->name instanceof Node\Identifier || strcasecmp($call->name->name, 'transaction') !== 0
                || $call->isFirstClassCallable() || self::nested($scope, $call) || count($call->args) > 2) { continue; }
            $callback = null;
            $callbackArgument = null;
            $valid = true;
            foreach ($call->args as $index => $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef
                    || $argument->name !== null && ! in_array($argument->name->name, ['callback', 'attempts'], true)) { $valid = false; break; }
                if ($argument->name?->name === 'callback' || $argument->name === null && $index === 0) {
                    if ($callback !== null || ! $argument->value instanceof Node\Expr\Closure) { $valid = false; break; }
                    $callback = $argument->value;
                    $callbackArgument = $argument;
                }
            }
            if (! $valid || $callback === null || $callback->byRef || $callback->params !== [] || $callback->returnType !== null
                || $callback->getComments() !== [] || $callback->stmts === []) { continue; }
            $return = $callback->stmts[array_key_last($callback->stmts)];
            $invoke = $return instanceof Node\Stmt\Return_ ? $return->expr : null;
            if (! $invoke instanceof Node\Expr\FuncCall || ! $invoke->name instanceof Node\Expr\Variable
                || ! is_string($invoke->name->name) || $invoke->args !== [] || $invoke->isFirstClassCallable()) { continue; }
            $local = $invoke->name->name;
            $formal = null;
            foreach ($scope->params as $index => $parameter) {
                if ($parameter->var instanceof Node\Expr\Variable && $parameter->var->name === $local) { $formal = $index; }
            }
            if ($formal === null || ! self::isolated($scope, $callback, $invoke, $local, $formal)) { continue; }
            yield ['call' => $call, 'callback' => $callback, 'callbackArgument' => $callbackArgument, 'operation' => $invoke, 'local' => $local, 'formal' => $formal];
        }
    }

    private static function isolated(Node $scope, Node\Expr\Closure $callback, Node\Expr\FuncCall $invoke, string $local, int $formal): bool
    {
        // A known source mutation of the selected facade service invalidates default dispatch.
        foreach ((new NodeFinder)->findInstanceOf($scope, Node\Expr\StaticCall::class) as $action) {
            if (!$action->class instanceof Node\Name || !$action->name instanceof Node\Identifier) { continue; }
            if (in_array(strtolower($action->class->toString()), ['illuminate\\support\\facades\\db','illuminate\\support\\facades\\facade'], true)
                && in_array(strtolower($action->name->name), ['swap','setfacadeapplication','resolved','clearresolvedinstance','clearresolvedinstances'], true)) { return false; }
        }
        $capture = null;
        foreach ($callback->uses as $use) {
            if ($use->var->name === $local) { if ($capture !== null || $use->byRef) { return false; } $capture = $use->var; }
        }
        if ($capture === null) { return false; }
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($scope, Node\Expr\Variable::class) as $variable) {
            if (! is_string($variable->name) || $variable->name === 'GLOBALS') { return false; }
            if ($variable->name === $local && $variable !== $scope->params[$formal]->var
                && $variable !== $capture && $variable !== $invoke->name) { return false; }
        }
        foreach ($finder->find($scope, static fn (Node $node): bool => $node instanceof Node\Stmt\Global_
            || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array(strtolower(ltrim($node->name->toString(), '\\')), ['extract', 'parse_str'], true)) as $unsafe) { return false; }
        foreach ($finder->find($callback->stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_
            || $node instanceof Node\Expr\Throw_ || $node instanceof Node\Expr\Exit_ || $node instanceof Node\Expr\Yield_
            || $node instanceof Node\Expr\YieldFrom || $node instanceof Node\Stmt\TryCatch
            || $node instanceof Node\Stmt\Break_ || $node instanceof Node\Stmt\Continue_) as $exit) {
            if ($exit !== $callback->stmts[array_key_last($callback->stmts)]) { return false; }
        }
        foreach ($finder->find($scope, static fn (Node $node): bool => $node !== $scope) as $node) {
            foreach ($node->getComments() as $comment) {
                if (preg_match('/@(?:phpstan-|psalm-)?(?:var|param|return|type)\b/i', $comment->getText()) === 1
                    && str_contains($comment->getText(), '$'.$local)) { return false; }
            }
        }
        return true;
    }

    private static function nested(Node $scope, Node $needle): bool
    {
        for ($parent = ContextualCollectionMembers::parent($scope, $needle); $parent !== null && $parent !== $scope;
            $parent = ContextualCollectionMembers::parent($scope, $parent)) {
            if ($parent instanceof Node\FunctionLike || $parent instanceof Node\Stmt\ClassLike) { return true; }
        }
        return false;
    }
}
