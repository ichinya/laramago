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

/** Source provenance for documented model members in a bare native chunk callback. */
final class ContextualCollectionMembers implements CodebaseScanHook
{
    private const COLLECTION = 'Illuminate\\Database\\Eloquent\\Collection';
    private const METHODS = ['chunk', 'chunkbyid', 'chunkbyiddesc', 'orderedchunkbyid'];
    /** @var array<string, array<string, mixed>|null> */
    private array $members = [];
    /** Exact-file issue authority; every proof shares the same scope/fetch nodes as the provider map. */
    private array $fileMembers = [];
    private int $fileMemberCount = 0;
    /** @var array<string, string|null> */
    private array $sources = [];
    private bool $complete = false;
    private bool $failed = false;
    private bool $started = false;
    private int $bytes = 0;
    public int $generation = 0;

    public function __construct(public readonly string $root = '.') {}

    public function getTargets(): array { return ['**']; }

    public function reset(): void
    {
        $this->generation++;
        $this->members = $this->fileMembers = $this->sources = []; $this->fileMemberCount = 0;
        $this->complete = $this->failed = false;
        $this->started = false;
        $this->bytes = 0;
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->reset(); $this->started = true; }
        $this->complete = false;
        if (! $this->started) { $this->failed = true; return; }
        foreach ($context->files as $file) {
            $this->cancellation($context);
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
            $candidates = [];
            foreach (self::scopes($nodes) as [$owner, $scope]) {
                foreach (self::candidates($scope) as $proof) {
                    $proof['file'] = $path;
                    $proof['owner'] = $owner;
                    $proof['scope'] = $scope;
                    $site = $proof['fetch']->getStartFilePos().':'.($proof['fetch']->getEndFilePos() + 1);
                    if ($this->fileMemberCount >= 100_000) { $this->failed = true; break 2; }
                    $this->fileMemberCount++;
                    $this->fileMembers[$path][$site] = array_key_exists($site, $this->fileMembers[$path] ?? [])
                        ? null : $proof;
                    foreach (self::keys($proof['fetch']) as $key) { $candidates[$key] = $proof; }
                }
            }
            // A property provider has no file field. Every property syntax reserves its spans.
            foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool =>
                $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch
                || $node instanceof Node\Expr\StaticPropertyFetch) as $fetch) {
                $this->cancellation($context);
                foreach (self::keys($fetch) as $key) {
                    if (count($this->members) >= 100_000) { $this->failed = true; break 2; }
                    $this->members[$key] = array_key_exists($key, $this->members) ? null : ($candidates[$key] ?? null);
                }
            }
        }
        if ($this->failed) { $this->members = $this->fileMembers = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    private function cancellation(CodebaseScanContext $context): void
    {
        try { $context->cancellation->throwIfCancelled(); }
        catch (\Throwable $error) { $this->failed = true; $this->complete = false; $this->members = $this->fileMembers = []; throw $error; }
    }

    /** @return array<string, mixed>|null */
    public function member(Span $span, string $property): ?array
    {
        $proof = $this->complete ? ($this->members[$span->start.':'.$span->end] ?? null) : null;
        return $proof !== null && $proof['fetch']->name->name === $property && $this->sourceMatches($proof['file']) ? $proof : null;
    }

    /** Only the issue hook supplies both the actual file and analyzed bytes; a provider cannot use this API. */
    public function fileMember(string $file, string $contents, Span $fetch, string $property): ?array
    {
        $path = $this->path($file);
        if (! $this->complete || $this->failed || ($this->sources[$path] ?? null) !== hash('sha256', $contents)
            || ! $this->sourceMatches($path)) { return null; }
        $proof = $this->fileMembers[$path][$fetch->start.':'.$fetch->end] ?? null;
        return $proof !== null && $proof['file'] === $path && $proof['fetch']->name->name === $property
            && [$proof['fetch']->getStartFilePos(), $proof['fetch']->getEndFilePos() + 1] === [$fetch->start, $fetch->end]
            ? $proof : null;
    }

    public function sourceMatches(string $file): bool
    {
        $path = $this->path($file);
        return array_key_exists($path, $this->sources) && $this->sources[$path] !== null
            && $this->sources[$path] === @hash_file('sha256', $path);
    }

    /** Unselected declarations are independently anchored; selected overlays must match their scan bytes. */
    public function declarationCurrent(string $file): bool
    {
        $path = $this->path($file);
        return ! array_key_exists($path, $this->sources) || $this->sourceMatches($file);
    }

    public function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) { $file = substr($file, 4); }
        if (! str_starts_with($file, '/') && preg_match('~^[A-Za-z]:/~', $file) !== 1) { $file = $this->root.'/'.$file; }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }

    /** @return list<string> */
    private static function keys(Node $node): array
    {
        $keys = [$node->getStartFilePos().':'.($node->getEndFilePos() + 1)];
        $name = $node->name ?? null;
        if ($name instanceof Node) { $keys[] = $name->getStartFilePos().':'.($name->getEndFilePos() + 1); }
        return array_values(array_unique($keys));
    }

    /** @param array<Node> $nodes
     * @return iterable<array{?string, Node\Stmt\ClassMethod|Node\Stmt\Function_}> */
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
        // A fresh assignment cannot sever the caller's external reference to this storage.
        foreach ($scope->params as $parameter) { if ($parameter->byRef) { return; } }
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->name), self::METHODS, true)
                || self::nested($scope, $call) || ($callback = self::callback($call)) === null) { continue; }
            $parameter = $callback->params[0] ?? null;
            if ($parameter === null || ! $parameter->type instanceof Node\Name
                || strcasecmp($parameter->type->toString(), self::COLLECTION) !== 0
                || $parameter->byRef || $parameter->variadic || $parameter->default !== null
                || ! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)
                || self::annotated([$callback, $parameter], [$parameter->var->name])) { continue; }
            $input = $parameter->var->name;
            $query = self::query($scope, $call);
            if ($query === null) { continue; }
            foreach ($finder->findInstanceOf($callback->stmts ?? [], Node\Stmt\Foreach_::class) as $loop) {
                if (! $loop->expr instanceof Node\Expr\Variable || $loop->expr->name !== $input || $loop->byRef
                    || ! $loop->valueVar instanceof Node\Expr\Variable || ! is_string($loop->valueVar->name)
                    || $loop->valueVar->name === $input || self::nested($callback, $loop)) { continue; }
                $local = $loop->valueVar->name;
                if (self::annotated($callback->stmts ?? [], [$input, $local]) || ! self::isolated($callback, $loop, $input, $local)) { continue; }
                foreach ($finder->findInstanceOf($loop->stmts, Node\Expr\PropertyFetch::class) as $fetch) {
                    if ($fetch->var instanceof Node\Expr\Variable && $fetch->var->name === $local
                        && $fetch->name instanceof Node\Identifier && ! self::nested($loop, $fetch)
                        && ! self::isWritten($loop, $fetch)) {
                        yield ['call' => $call, 'callback' => $callback, 'parameter' => $parameter,
                            'input' => $input, 'local' => $local, 'loop' => $loop, 'fetch' => $fetch,
                            'model' => $query['model'], 'query' => $query['local'], 'origin' => $query['origin']];
                    }
                }
            }
        }
    }

    private static function callback(Node\Expr\MethodCall $call): ?Node\Expr\Closure
    {
        $found = null;
        foreach ($call->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) { return null; }
            if ($argument->name?->name === 'callback' || $argument->name === null && $index === 1) {
                if ($found !== null || ! $argument->value instanceof Node\Expr\Closure || $argument->value->byRef) { return null; }
                $found = $argument->value;
            }
        }
        return $found;
    }

    /** @return array{model: string, local: string, origin: Node\Expr\StaticCall}|null */
    private static function query(Node\Stmt\ClassMethod|Node\Stmt\Function_ $scope, Node\Expr\MethodCall $call): ?array
    {
        $receiver = $call->var;
        while ($receiver instanceof Node\Expr\MethodCall) {
            if (! $receiver->name instanceof Node\Identifier || strtolower($receiver->name->name) !== 'with' || count($receiver->args) !== 1) { return null; }
            foreach ($receiver->args as $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef
                    || ! $argument->value instanceof Node\Expr\Array_) { return null; }
                $relations = PhpSource::value($argument->value);
                if (! is_array($relations) || array_filter($relations, static fn ($relation): bool => ! is_string($relation)) !== []) { return null; }
            }
            $receiver = $receiver->var;
        }
        if (! $receiver instanceof Node\Expr\Variable || ! is_string($receiver->name) || $receiver->name === 'this') { return null; }
        $local = $receiver->name;
        if (self::annotated([$scope], [$local])) { return null; }
        $origin = null;
        $binding = null;
        foreach ($scope->stmts ?? [] as $statement) {
            if ($statement->getStartFilePos() >= $call->getStartFilePos()) { break; }
            $assignment = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if ($assignment instanceof Node\Expr\Assign && $assignment->var instanceof Node\Expr\Variable
                && $assignment->var->name === $local && $assignment->expr instanceof Node\Expr\StaticCall
                && $assignment->expr->class instanceof Node\Name && $assignment->expr->name instanceof Node\Identifier
                && strtolower($assignment->expr->name->name) === 'query' && $assignment->expr->args === []) {
                if ($origin !== null) { return null; }
                $origin = $assignment->expr;
                $binding = $assignment->var;
            }
        }
        if ($origin === null) { return null; }
        for ($parent = self::parent($scope, $call); $parent !== null && $parent !== $scope; $parent = self::parent($scope, $parent)) {
            if ($parent instanceof Node\Stmt\Foreach_ || $parent instanceof Node\Stmt\For_
                || $parent instanceof Node\Stmt\While_ || $parent instanceof Node\Stmt\Do_) { return null; }
        }
        foreach ((new NodeFinder)->findInstanceOf($scope->stmts ?? [], Node\Expr\Variable::class) as $variable) {
            if ($variable->name !== $local || $variable === $binding) { continue; }
            if ($variable->getStartFilePos() < $origin->getStartFilePos()) { return null; }
            $parent = self::parent($scope, $variable);
            if ($parent instanceof Node\Expr\Clone_) {
                $count = self::parent($scope, $parent);
                if (! $count instanceof Node\Expr\MethodCall || ! $count->name instanceof Node\Identifier
                    || strtolower($count->name->name) !== 'count' || $count->args !== []) { return null; }
                continue;
            }
            if (! $parent instanceof Node\Expr\MethodCall || $parent->var !== $variable
                || ! $parent->name instanceof Node\Identifier || ! in_array(strtolower($parent->name->name),
                    ['where', 'wherenull', 'with', 'count', ...self::METHODS], true)) { return null; }
            // Follow every receiver link: an outer setModel() still mutates the original builder.
            for ($link = $parent; $link instanceof Node\Expr\MethodCall; ) {
                if (! $link->name instanceof Node\Identifier || ! in_array(strtolower($link->name->name),
                    ['where', 'wherenull', 'with', 'count', ...self::METHODS], true)) { return null; }
                $outer = self::parent($scope, $link);
                $link = $outer instanceof Node\Expr\MethodCall && $outer->var === $link ? $outer : null;
            }
        }
        return ['model' => $origin->class->toString(), 'local' => $local, 'origin' => $origin];
    }

    private static function isolated(Node\Expr\Closure $callback, Node\Stmt\Foreach_ $loop, string $input, string $local): bool
    {
        foreach ($callback->uses as $use) { if (in_array($use->var->name, [$input, $local], true)) { return false; } }
        $finder = new NodeFinder;
        if ($finder->findFirst([$callback], static fn (Node $node): bool =>
            $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name === 'GLOBALS')
            || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label) !== null) { return false; }
        foreach ($finder->findInstanceOf($callback->stmts ?? [], Node\Expr\Variable::class) as $variable) {
            if (! in_array($variable->name, [$input, $local], true)) { continue; }
            $parent = self::parent($callback, $variable);
            if ($variable->name === $input) {
                if ($parent === $loop && $loop->expr === $variable) { continue; }
                // The contract verifier must prove this ordinary helper reads the collection only.
                if ($parent instanceof Node\Arg && ! $parent->unpack && ! $parent->byRef
                    && $variable->getStartFilePos() < $loop->getStartFilePos()) { continue; }
                return false;
            }
            if ($parent === $loop && $loop->valueVar === $variable) { continue; }
            if ($parent instanceof Node\Expr\PropertyFetch && $parent->var === $variable && $parent->name instanceof Node\Identifier) { continue; }
            if ($parent instanceof Node\Expr\MethodCall && $parent->var === $variable && $parent->name instanceof Node\Identifier) { continue; }
            return false;
        }
        return true;
    }

    /** @param array<Node> $nodes @param list<string> $variables */
    private static function annotated(array $nodes, array $variables): bool
    {
        return (new NodeFinder)->findFirst($nodes, static function (Node $node) use ($variables): bool {
            foreach ($node->getComments() as $comment) {
                if (preg_match('/@(?:phpstan-|psalm-)?(?:param|var|type)\b/i', $comment->getText()) === 1) {
                    foreach ($variables as $variable) { if (str_contains($comment->getText(), '$'.$variable)) { return true; } }
                }
            }
            return false;
        }) !== null;
    }

    public static function parent(Node $root, Node $needle): ?Node
    {
        foreach ($root->getSubNodeNames() as $name) {
            $children = $root->$name;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if (! $child instanceof Node) { continue; }
                if ($child === $needle) { return $root; }
                if (($found = self::parent($child, $needle)) !== null) { return $found; }
            }
        }
        return null;
    }

    private static function nested(Node $root, Node $needle): bool
    {
        for ($parent = self::parent($root, $needle); $parent !== null && $parent !== $root; $parent = self::parent($root, $parent)) {
            if ($parent instanceof Node\FunctionLike || $parent instanceof Node\Stmt\ClassLike) { return true; }
        }
        return false;
    }

    public static function isWritten(Node $root, Node\Expr\PropertyFetch $fetch): bool
    {
        $parent = self::parent($root, $fetch);
        $target = $fetch;
        while ($parent instanceof Node\Expr\ArrayDimFetch || $parent instanceof Node\ArrayItem || $parent instanceof Node\Expr\List_
            || $parent instanceof Node\Expr\Array_) {
            // An offset expression is read even when the selected array cell is written.
            if ($parent instanceof Node\Expr\ArrayDimFetch && $parent->var !== $target
                || $parent instanceof Node\ArrayItem && $parent->key === $target) { return false; }
            if ($parent instanceof Node\ArrayItem && $parent->byRef) { return true; }
            $target = $parent;
            $parent = self::parent($root, $parent);
        }
        return ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\Expr\AssignOp)
            && $parent->var === $target || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PostInc
            || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostDec
            || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Expr\Empty_
            || $parent instanceof Node\Stmt\Foreach_ && ($parent->valueVar === $target || $parent->keyVar === $target)
            || $parent instanceof Node\Arg && $parent->byRef;
    }
}
