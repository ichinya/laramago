<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** Current native callable/source contracts, without executing PHP source. */
final class WeakNumericFloatCalls
{
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_NODES = 20_000;
    /** @var array<string, array<string, mixed>> */
    private array $files = [];
    private int $cacheBytes = 0;

    public function __construct(private readonly string $root) {}
    public function reset(): void { $this->files = []; $this->cacheBytes = 0; }

    /** @return array<string, mixed>|null */
    public function returnSite(IssueFilterContext $context, string $target, int $start, int $end): ?array
    {
        $file = $this->read($context->file, $context->contents);
        if ($file === null || ! $file['weak']) { return null; }
        foreach ($file['scopes'] as $scope) {
            if (strcasecmp($scope['target'], $target) !== 0 || ! self::safeScope($scope['node'])) { continue; }
            $metadata = $this->callable($context, $scope, $file);
            $node = $scope['node'];
            if ($metadata === null || ! self::nativeFloat($node->returnType) || ! self::unannotated($node, 'return')
                || ! $this->floatType($context, $metadata->declaredReturnType, $node->returnType, $file)
                || ! $this->floatType($context, $metadata->returnType, $node->returnType, $file)) { continue; }
            foreach (self::bodyNodes($node->getStmts() ?? []) as $body) {
                if ($body instanceof Node\Stmt\Return_ && $body->expr !== null && self::span($body->expr) === [$start, $end]) {
                    return ['scope' => $scope, 'metadata' => $metadata, 'file' => $file, 'expression' => $body->expr, 'mode' => 'declaring-file'];
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function argumentSite(IssueFilterContext $context, string $target, int $position, int $start, int $end, int $secondaryStart, int $secondaryEnd): ?array
    {
        $callerFile = $this->read($context->file, $context->contents);
        if ($callerFile === null || ! $callerFile['weak']) { return null; }
        foreach ($callerFile['scopes'] as $callerScope) {
            if (! self::safeScope($callerScope['node']) || $this->callable($context, $callerScope, $callerFile) === null) { continue; }
            foreach (self::bodyNodes($callerScope['node']->getStmts() ?? []) as $call) {
                if (! $call instanceof Node\Expr\FuncCall && ! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) { continue; }
                // Genuine Mago function secondary spans name the callee; method spans cover the call.
                $secondarySyntax = $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name ? $call->name : $call;
                if (self::span($secondarySyntax) !== [$secondaryStart, $secondaryEnd] || $call->isFirstClassCallable()) { continue; }
                $argument = $call->args[$position] ?? null;
                if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || self::span($argument->value) !== [$start, $end]) { continue; }
                $callee = $this->declaredTarget($context, $target);
                if ($callee === null || ! $this->dispatch($call, $target, $callerScope, $callee)) { continue; }
                $metadata = $this->callable($context, $callee['scope'], $callee['file']);
                if ($metadata === null || ! self::unannotated($callee['scope']['node'], 'param')) { continue; }
                $bound = $this->parameter($call, $position, $metadata, $callee['scope']['node']);
                if ($bound === null) { continue; }
                [$parameter, $syntax] = $bound;
                if (! self::nativeFloat($syntax->type) || $syntax->byRef || $syntax->variadic
                    || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                    || $parameter->outType !== null || $parameter->closureThisType !== null
                    || ! $this->floatType($context, $parameter->declaredType, $syntax->type, $callee['file'])
                    || ! $this->floatType($context, $parameter->type, $syntax->type, $callee['file'])
                    || $parameter->name !== '$'.$syntax->var->name
                    || ! $this->located($parameter->nameLocation, $syntax->var, $callee['file'])) { continue; }
                return ['scope' => $callerScope, 'callee' => $callee, 'metadata' => $metadata, 'parameter' => $parameter,
                    'argument' => $argument, 'mode' => 'caller-file'];
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function declaredTarget(IssueFilterContext $context, string $target): ?array
    {
        if (str_contains($target, '::')) {
            [$class, $name] = explode('::', $target, 2);
            $metadata = $context->codebase->getMethod($class, $name);
        } else { $metadata = $context->codebase->getFunction($target); }
        if ($metadata === null || $metadata->location->file === null) { return null; }
        $file = $this->read($metadata->location->file);
        if ($file === null) { return null; }
        foreach ($file['scopes'] as $scope) {
            if (strcasecmp($scope['target'], $target) === 0) { return ['scope' => $scope, 'file' => $file]; }
        }
        return null;
    }

    private function dispatch(Node $call, string $target, array $caller, array $callee): bool
    {
        $scope = $callee['scope']; $node = $scope['node'];
        if ($call instanceof Node\Expr\FuncCall) {
            if ($scope['owner'] !== null || ! $call->name instanceof Node\Name) { return false; }
            $resolved = $call->name->getAttribute('resolvedName');
            if ($call->name instanceof Node\Name\FullyQualified) { $resolved = $call->name; }
            if (! $resolved instanceof Node\Name) {
                $resolved = $call->name->getAttribute('namespacedName');
                // A same-file explicit declaration binds an unqualified namespace function.
                if ($callee['file']['path'] !== $caller['path']) { return false; }
            }
            return $resolved instanceof Node\Name && strcasecmp($resolved->toString(), $target) === 0;
        }
        if (! $node instanceof Node\Stmt\ClassMethod || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->name, $node->name->name) !== 0) { return false; }
        if ($call instanceof Node\Expr\MethodCall) {
            // Native private source method lookup is lexical even when the owner class is nonfinal.
            return $call->var instanceof Node\Expr\Variable && $call->var->name === 'this' && $node->isPrivate() && ! $node->isStatic()
                && $caller['owner'] !== null && strcasecmp($caller['owner'], $scope['owner']) === 0;
        }
        if (! $call instanceof Node\Expr\StaticCall || ! $node->isStatic() || ! $call->class instanceof Node\Name) { return false; }
        $owner = $scope['class'];
        if (! $owner instanceof Node\Stmt\Class_ || ! $owner->isFinal()) { return false; }
        $class = $call->class->toString();
        if (in_array(strtolower($class), ['self', 'static', 'parent'], true)) { return false; }
        return strcasecmp(ltrim($class, '\\'), $scope['owner']) === 0;
    }

    /** @return array{object, Node\Param}|null */
    private function parameter(Node $call, int $position, FunctionLikeMetadata $metadata, Node\FunctionLike $node): ?array
    {
        $bound = []; $seenNamed = false; $selected = null;
        foreach ($call->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack) { return null; }
            if ($argument->name !== null) {
                $seenNamed = true; $slot = null;
                foreach ($metadata->parameters as $candidate => $parameter) { if ($parameter->name === '$'.$argument->name->name) { $slot = $candidate; break; } }
                if ($slot === null) { return null; }
            } else { if ($seenNamed) { return null; } $slot = $index; }
            if (isset($bound[$slot]) || ! isset($metadata->parameters[$slot], $node->getParams()[$slot])) { return null; }
            $bound[$slot] = true;
            if ($index === $position) { $selected = [$metadata->parameters[$slot], $node->getParams()[$slot]]; }
        }
        foreach ($node->getParams() as $index => $parameter) {
            if ($parameter->byRef || $parameter->variadic || ! isset($bound[$index]) && $parameter->default === null) { return null; }
        }
        return $selected;
    }

    private function callable(IssueFilterContext $context, array $scope, array $file): ?FunctionLikeMetadata
    {
        $node = $scope['node'];
        if ($scope['owner'] === null) { $metadata = $context->codebase->getFunction($scope['name']); }
        else {
            $metadata = $context->codebase->getMethod($scope['owner'], $scope['name']);
            $declaring = $context->codebase->getDeclaringMethod($scope['owner'], $scope['name']);
            if ($metadata === null || $declaring === null || $metadata != $declaring
                || $metadata->identifier->class !== $declaring->identifier->class
                || $metadata->location->file !== $declaring->location->file || $metadata->location->span != $declaring->location->span) { return null; }
            $class = $context->codebase->getClass($scope['owner']);
            $syntax = $scope['class'];
            if ($class === null || $class->kind !== ClassLikeKind::Class_ || $class->hasIncompleteHierarchy()
                || $class->templates !== [] || $class->typeAliases !== [] || ! $this->namedMixins($context, $scope, $class->mixins)
                || strcasecmp($class->name, $scope['owner']) !== 0 || ! $syntax instanceof Node\Stmt\Class_
                || ! $this->located($class->location, $syntax, $file) || ! $this->located($class->nameLocation, $syntax->name, $file)
                || $class->flags->contains(MetadataFlags::FINAL) !== $syntax->isFinal()) { return null; }
        }
        if ($metadata === null || $metadata->abstract || $metadata->templates !== [] || $metadata->whereConstraints !== []
            || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) || $metadata->flags->contains(MetadataFlags::MAGIC_METHOD)
            || $metadata->flags->contains(MetadataFlags::BUILTIN) || $node->byRef
            || strcasecmp($metadata->identifier->class ?? '', $scope['owner'] ?? '') !== 0
            || strcasecmp($metadata->identifier->name, $scope['name']) !== 0
            || ! $this->located($metadata->location, $node, $file) || ! $this->located($metadata->nameLocation, $node->name, $file)
            || $metadata->hasDocblock !== ($node->getDocComment() !== null) || count($metadata->parameters) !== count($node->getParams())) { return null; }
        if ($node instanceof Node\Stmt\ClassMethod) {
            $visibility = $node->isPrivate() ? Visibility::Private : ($node->isProtected() ? Visibility::Protected : Visibility::Public);
            if ($metadata->kind !== FunctionLikeKind::Method || $metadata->visibility !== $visibility
                || $metadata->static !== $node->isStatic() || $metadata->abstract !== $node->isAbstract() || $metadata->final !== $node->isFinal()) { return null; }
        } elseif ($metadata->kind !== FunctionLikeKind::Function_) { return null; }
        foreach ($node->getParams() as $index => $parameter) {
            $native = $metadata->parameters[$index];
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)
                || $native->name !== '$'.$parameter->var->name || $native->flags->contains(MetadataFlags::BY_REFERENCE) !== $parameter->byRef
                || $native->flags->contains(MetadataFlags::VARIADIC) !== $parameter->variadic
                || $native->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($parameter->default !== null)
                || ($native->defaultType !== null) !== ($parameter->default !== null)
                || ! $this->located($native->location, $parameter, $file)
                || ! $this->located($native->nameLocation, $parameter->var, $file)) { return null; }
        }
        return $metadata;
    }

    private function floatType(IssueFilterContext $context, ?object $metadata, Node $type, array $file): bool
    {
        return $metadata !== null && ! $metadata->fromDocblock && ! $metadata->inferred
            && $context->types->equals($metadata->type, Type::float()) && $this->located($metadata->location, $type, $file);
    }

    /** @return array<string, mixed>|null */
    private function read(string $file, ?string $analyzed = null): ?array
    {
        $path = $this->path($file); $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > self::MAX_FILE_BYTES || $analyzed !== null && $contents !== $analyzed) { return null; }
        $hash = hash('sha256', $contents); $key = hash('sha256', $path."\0".$hash);
        if (isset($this->files[$key])) { return $this->files[$key]; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
            $counter = new class extends NodeVisitorAbstract {
                public int $count = 0; public bool $failed = false;
                public function enterNode(Node $node): ?int { if (++$this->count > 20_000) { $this->failed = true; return NodeTraverser::STOP_TRAVERSAL; } return null; }
            };
            (new NodeTraverser($counter))->traverse($nodes);
            if ($counter->failed) { return null; }
            $weak = self::weak($nodes);
            $resolver = new NameResolver;
            $mixins = new class($resolver) extends NodeVisitorAbstract {
                public function __construct(private readonly NameResolver $resolver) {}
                public function enterNode(Node $node): ?Node
                {
                    if ($node instanceof Node\Stmt\Class_) {
                        $names = WeakNumericFloatCalls::mixinNames($node->getDocComment()?->getText() ?? '');
                        if ($names !== null) {
                            $names = array_map(function (string $name): string {
                                $syntax = str_starts_with($name, '\\') ? new Node\Name\FullyQualified(substr($name, 1)) : new Node\Name($name);
                                return $this->resolver->getNameContext()->getResolvedClassName($syntax)->toString();
                            }, $names);
                        }
                        $node->setAttribute('laramagoWeakFloatNamedMixins', $names);
                    }
                    return null;
                }
            };
            $nodes = (new NodeTraverser($resolver, $mixins))->traverse($nodes);
            $scopes = [];
            foreach (self::scopes($nodes) as [$class, $node]) {
                $owner = $class?->namespacedName?->toString();
                $name = $node instanceof Node\Stmt\Function_ ? $node->namespacedName?->toString() : $node->name->name;
                if ($name === null) { continue; }
                $scopes[] = ['owner' => $owner, 'class' => $class, 'name' => $name, 'target' => $owner === null ? $name : $owner.'::'.$name,
                    'node' => $node, 'path' => $path];
            }
        } catch (\PhpParser\Error) { return null; }
        $bytes = strlen($contents);
        while ($this->files !== [] && (count($this->files) >= 16 || $this->cacheBytes + $bytes > 8 * 1024 * 1024)) {
            $oldest = array_key_first($this->files); $this->cacheBytes -= $this->files[$oldest]['bytes']; unset($this->files[$oldest]);
        }
        $row = ['path' => $path, 'hash' => $hash, 'bytes' => $bytes, 'weak' => $weak, 'scopes' => $scopes];
        $this->cacheBytes += $bytes;
        return $this->files[$key] = $row;
    }

    /** Weak mode is file-scoped; unknown or invalid declare syntax supplies no authority. */
    public static function weak(array $nodes): bool
    {
        $declares = (new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Declare_::class);
        if ($declares === []) { return true; }
        if (count($declares) !== 1 || ($nodes[0] ?? null) !== $declares[0] || $declares[0]->stmts !== null || count($declares[0]->declares) !== 1) { return false; }
        $directive = $declares[0]->declares[0];
        return $directive->key->name === 'strict_types' && $directive->value instanceof Node\Scalar\Int_ && $directive->value->value === 0;
    }

    private static function nativeFloat(?Node $node): bool { return $node instanceof Node\Identifier && strtolower($node->name) === 'float'; }
    /** Source tags only; no generic, union, pseudo/self/static, or dialect-specific mixins. */
    public static function mixinNames(string $doc): ?array
    {
        $names = [];
        $count = preg_match_all('/@(?:[a-z]+-)?mixin\b/i', $doc);
        foreach (preg_split('/\r\n|\n|\r/', $doc) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '/**')) { $line = trim(substr($line, 3)); }
            if (str_ends_with($line, '*/')) { $line = trim(substr($line, 0, -2)); }
            if (str_starts_with($line, '*')) { $line = trim(substr($line, 1)); }
            if (! str_contains(strtolower($line), 'mixin')) { continue; }
            if (preg_match('/^@mixin\s+((?:\\\\)?[a-z_\x80-\xff][a-z0-9_\x80-\xff]*(?:\\\\[a-z_\x80-\xff][a-z0-9_\x80-\xff]*)*)\s*$/iD', $line, $match) !== 1) {
                if (preg_match('/@(?:[a-z]+-)?mixin\b/i', $line) === 1) { return null; }
                continue;
            }
            $name = $match[1];
            if (in_array(strtolower($name), ['self', 'static', 'parent', 'int', 'float', 'bool', 'string', 'mixed', 'null', 'never', 'object', 'array'], true)
                || str_starts_with(strtolower($name), 'namespace\\')) { return null; }
            $names[] = $name;
        }
        if (count($names) !== $count) { return null; }
        return $names;
    }

    /** Match source-written names to frozen native mixins, never use a mixin for method lookup. */
    private function namedMixins(IssueFilterContext $context, array $scope, array $mixins): bool
    {
        $syntax = $scope['class'];
        if (! $syntax instanceof Node\Stmt\Class_) { return false; }
        $names = $syntax->getAttribute('laramagoWeakFloatNamedMixins');
        if (! is_array($names) || count($names) !== count($mixins)) { return false; }
        $source = [];
        foreach ($names as $name) {
            $key = strtolower(ltrim($name, '\\'));
            if (isset($source[$key])) { return false; }
            $source[$key] = true;
        }
        $native = [];
        foreach ($mixins as $mixin) {
            if (! $mixin instanceof Type || count($mixin->atomicTypes) !== 1 || $mixin->flags->hadTemplate
                || $mixin->flags->byReference || $mixin->flags->fromTemplateDefault || $mixin->flags->fromUnspecifiedTemplate) { return false; }
            $atom = $mixin->atomicTypes[0];
            if (! $atom instanceof NamedObjectType || ($atom->parameters ?? []) !== [] || ($atom->variances ?? []) !== []
                || ($atom->intersections ?? []) !== [] || $atom->static || $atom->isThis || $atom->remappedParameters) { return false; }
            $key = strtolower(ltrim($atom->name, '\\'));
            $target = $context->codebase->getClassLike($atom->name);
            if (isset($native[$key]) || ! isset($source[$key]) || $target === null || $target->hasIncompleteHierarchy()) { return false; }
            $native[$key] = true;
        }
        return count($source) === count($native);
    }
    private static function unannotated(Node $node, string $tag): bool { return preg_match('/@(?:phpstan-|psalm-)?'.$tag.'\b/i', $node->getDocComment()?->getText() ?? '') !== 1; }
    private static function safeScope(Node\FunctionLike $scope): bool
    {
        if ($scope->returnsByRef()) { return false; }
        foreach ($scope->getParams() as $parameter) { if ($parameter->byRef || $parameter->variadic) { return false; } }
        foreach (self::bodyNodes($scope->getStmts() ?? []) as $node) {
            if ($node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Goto_
                || $node instanceof Node\Stmt\Label || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
                || $node instanceof Node\Expr\Variable && ! is_string($node->name) || $node instanceof Node\Arg && $node->byRef
                || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true)) { return false; }
        }
        return true;
    }
    private function located(?SourceLocation $location, ?Node $node, array $file): bool
    {
        return $location !== null && $node !== null && $location->file !== null && $this->path($location->file) === $file['path']
            && [$location->span->start, $location->span->end] === self::span($node) && $file['hash'] === @hash_file('sha256', $file['path']);
    }
    public function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, '//?/')) { $file = substr($file, 4); }
        if (! str_starts_with($file, '/') && preg_match('~^[A-Za-z]:/~', $file) !== 1) { $file = str_replace('\\', '/', $this->root).'/'.$file; }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }
    public static function span(Node $node): array { return [$node->getStartFilePos(), $node->getEndFilePos() + 1]; }
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { yield from self::scopes($node->stmts); }
            elseif ($node instanceof Node\Stmt\Function_) { yield [null, $node]; }
            elseif ($node instanceof Node\Stmt\Class_ && $node->name !== null) { foreach ($node->getMethods() as $method) { yield [$node, $method]; } }
        }
    }
    private static function bodyNodes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) { continue; }
            yield $node;
            foreach ($node->getSubNodeNames() as $key) { $child = $node->$key; yield from self::bodyNodes(is_array($child) ? $child : [$child]); }
        }
    }
}
