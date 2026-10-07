<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Certify a protective read followed by a throwing scalar check; never infer a new value. */
final class DefensiveArrayGuardProofs implements InitializationHook, CodebaseScanHook
{
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header', 'argv', 'argc'];
    /** @var array<string, array{hash:string, proofs:list<array<string,mixed>>}> */
    private array $files = [];
    private bool $complete = false;
    private bool $failed = false;
    private bool $started = false;
    private int $bytes = 0;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->files = [];
        $this->complete = $this->failed = $this->started = false;
        $this->bytes = 0;
    }

    public function getTargets(): array { return ['**']; }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->files = [];
            $this->failed = false;
            $this->started = true;
            $this->bytes = 0;
        } elseif (! $this->started || $this->complete) { $this->failed = true; }
        $this->complete = false;
        foreach ($context->files as $file) {
            if ($context->cancellation->isCancelled()) { $this->failed = true; break; }
            $path = self::path($file->path);
            $this->bytes += strlen($file->contents);
            if ($this->failed || $path === '' || isset($this->files[$path]) || strlen($file->contents) > 2_000_000
                || $this->bytes > 64 * 1024 * 1024 || count($this->files) >= 100_000) { $this->failed = true; break; }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $proofs = [];
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem
                && strtolower($node->key->name) === 'ticks') === null) {
                foreach (self::scopes($nodes) as [$scope, $class]) {
                    if (! self::localScope($scope)) { continue; }
                    foreach (self::candidates($scope) as $proof) {
                        if (count($proofs) >= 1024) { $this->failed = true; break 2; }
                        $proofs[] = $proof + ['scope' => $scope, 'class' => $class,
                            'owner' => $class?->namespacedName?->toString(), 'file' => $file->path,
                            'diskFile' => $this->disk($file->path), 'hash' => hash('sha256', $file->contents)];
                    }
                }
            }
            $this->files[$path] = ['hash' => hash('sha256', $file->contents), 'proofs' => $proofs];
        }
        if ($context->cancellation->isCancelled()) { $this->failed = true; }
        if ($this->failed) { $this->files = []; }
        $this->complete = $context->lastBatch && $this->started && ! $this->failed;
    }

    /** @return list<array<string,mixed>> */
    public function proofs(string $file, string $contents): array
    {
        $entry = $this->complete ? ($this->files[self::path($file)] ?? null) : null;
        return $entry !== null && hash('sha256', $contents) === $entry['hash'] ? $entry['proofs'] : [];
    }

    /** @param array<string,mixed> $proof */
    public function valid(Codebase $codebase, TypeComparator $types, array $proof): bool
    {
        return self::current($proof) && self::caller($codebase, $proof)
            && self::predicate($codebase, $types, $proof['predicate'], 'is_array')
            && self::predicate($codebase, $types, $proof['string'], 'is_string')
            && self::exception($codebase, $types, $proof['exception']);
    }

    public static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }

    public static function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) { $file = substr($file, 4); }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }

    /** @param array<string,mixed> $proof */
    public static function current(array $proof): bool
    {
        clearstatcache(true, $proof['diskFile']);
        $size = @filesize($proof['diskFile']);
        $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($proof['diskFile']) : false;
        return $contents !== false && hash('sha256', $contents) === $proof['hash'];
    }

    private function disk(string $file): string
    {
        $file = self::path($file);
        return str_starts_with($file, '/') || preg_match('~^[a-z]:/~i', $file) === 1 ? $file : self::path($this->root).'/'.$file;
    }

    /** @return iterable<array{Node\Stmt\Function_|Node\Stmt\ClassMethod, ?Node\Stmt\Class_}> */
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { yield from self::scopes($node->stmts); }
            elseif ($node instanceof Node\Stmt\Function_) { yield [$node, null]; }
            elseif ($node instanceof Node\Stmt\Class_ && $node->name !== null && $node->namespacedName !== null) {
                foreach ($node->getMethods() as $method) { if ($method->stmts !== null) { yield [$method, $node]; } }
            }
        }
    }

    private static function localScope(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope): bool
    {
        if ($scope->byRef || $scope->stmts === null) { return false; }
        foreach ($scope->params as $parameter) {
            if ($parameter->byRef || $parameter->variadic || self::local($parameter->var) === null) { return false; }
        }
        foreach ((new NodeFinder)->find($scope->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Variable
            && ! is_string($node->name) || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_
            || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom || $node instanceof Node\Stmt\Goto_
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassLike) as $node) { return false; }
        foreach ((new NodeFinder)->findInstanceOf($scope->stmts, Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name || in_array(strtolower($call->name->getLast()),
                ['extract', 'compact', 'get_defined_vars', 'parse_str', 'assert'], true)) { return false; }
        }
        foreach ((new NodeFinder)->find($scope->stmts, static fn (Node $node): bool => $node->getComments() !== []) as $node) {
            foreach ($node->getComments() as $comment) {
                if (preg_match('/@(var|phpstan-var|psalm-var|phpstan-assert|psalm-assert)\b/i', $comment->getText()) === 1) { return false; }
            }
        }
        return true;
    }

    /** @return iterable<array<string,mixed>> */
    private static function candidates(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope): iterable
    {
        $seen = [];
        foreach ($scope->params as $parameter) { $seen[$parameter->var->name] = true; }
        $origins = [];
        foreach ($scope->stmts ?? [] as $index => $statement) {
            $assignment = $statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign ? $statement->expr : null;
            $output = $assignment === null ? null : self::local($assignment->var);
            $ternary = $assignment?->expr;
            if ($output !== null && ! isset($seen[$output]) && $ternary instanceof Node\Expr\Ternary
                && $ternary->cond instanceof Node\Expr\FuncCall && self::oneLocal($ternary->cond, 'is_array') !== null
                && $ternary->if instanceof Node\Expr\BinaryOp\Coalesce && self::isNull($ternary->if->right) && self::isNull($ternary->else)) {
                $input = self::oneLocal($ternary->cond, 'is_array');
                $read = $ternary->if->left;
                $next = $scope->stmts[$index + 1] ?? null;
                if ($input !== $output && isset($origins[$input]) && $read instanceof Node\Expr\ArrayDimFetch
                    && self::local($read->var) === $input && ($read->dim instanceof Node\Scalar\String_ || $read->dim instanceof Node\Scalar\Int_)
                    && $next instanceof Node\Stmt\If_ && $next->else === null && $next->elseifs === []
                    && $next->cond instanceof Node\Expr\BooleanNot && $next->cond->expr instanceof Node\Expr\FuncCall
                    && self::oneLocal($next->cond->expr, 'is_string') === $output && count($next->stmts) === 1
                    && $next->stmts[0] instanceof Node\Stmt\Expression && $next->stmts[0]->expr instanceof Node\Expr\Throw_
                    && $next->stmts[0]->expr->expr instanceof Node\Expr\New_) {
                    $exception = $next->stmts[0]->expr->expr;
                    if ($exception->class instanceof Node\Name && strcasecmp($exception->class->toString(), 'UnexpectedValueException') === 0
                        && self::plain($exception->args) && count($exception->args) === 1 && $exception->args[0]->value instanceof Node\Scalar\String_) {
                        yield ['input' => $input, 'output' => $output, 'predicate' => $ternary->cond,
                            'string' => $next->cond->expr, 'exception' => $exception];
                    }
                }
            }
            $fresh = $output !== null && ! isset($seen[$output]) && ($assignment?->expr instanceof Node\Expr\CallLike)
                && ! $assignment->expr->isFirstClassCallable()
                && (new NodeFinder)->findFirst([$assignment->expr], static fn (Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $output) === null;
            foreach ((new NodeFinder)->findInstanceOf([$statement], Node\Expr\Variable::class) as $variable) {
                if (is_string($variable->name)) { unset($origins[$variable->name]); $seen[$variable->name] = true; }
            }
            if ($fresh) { $origins[$output] = true; }
        }
    }

    private static function oneLocal(Node\Expr\FuncCall $call, string $name): ?string
    {
        return $call->name instanceof Node\Name && strcasecmp($call->name->toString(), $name) === 0
            && self::plain($call->args) && count($call->args) === 1 ? self::local($call->args[0]->value) : null;
    }

    private static function local(?Node $node): ?string
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $node->name) === 1
            && ! in_array($node->name, self::RESERVED, true) ? $node->name : null;
    }

    private static function plain(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->byRef || $argument->unpack) { return false; }
        }
        return true;
    }

    private static function isNull(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString()) === 'null';
    }

    private static function predicate(Codebase $codebase, TypeComparator $types, Node\Expr\FuncCall $call, string $name): bool
    {
        if (! $call->name instanceof Node\Name || strcasecmp($call->name->toString(), $name) !== 0) { return false; }
        if (! $call->name instanceof Node\Name\FullyQualified) {
            $namespaced = $call->name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), $name) !== 0
                && $codebase->getFunction($namespaced->toString()) !== null) { return false; }
        }
        $metadata = $codebase->getFunction($name);
        $parameter = $metadata?->parameters[0] ?? null;
        return $metadata !== null && $metadata->kind === FunctionLikeKind::Function_
            && $metadata->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_
            && $metadata->identifier->class === null && strcasecmp($metadata->identifier->name, $name) === 0
            && strcasecmp($metadata->name, $name) === 0 && strcasecmp($metadata->originalName, $name) === 0
            && $metadata->flags->contains(MetadataFlags::BUILTIN) && ! $metadata->flags->contains(MetadataFlags::USER_DEFINED)
            && ! $metadata->flags->contains(MetadataFlags::BY_REFERENCE) && count($metadata->parameters) === 1
            && $metadata->templates === [] && $metadata->globalsAccessed === [] && $metadata->whereConstraints === []
            && $metadata->assertions === [] && $metadata->ifFalseAssertions === [] && ! $metadata->assertionsInferred
            && $parameter !== null && $parameter->name === '$value'
            && ! $parameter->flags->contains(MetadataFlags::BY_REFERENCE) && ! $parameter->flags->contains(MetadataFlags::VARIADIC)
            && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) && $parameter->type !== null && $parameter->declaredType !== null
            && $types->equals($parameter->type->type, Type::mixed()) && $types->equals($parameter->declaredType->type, Type::mixed())
            && $metadata->returnType !== null && $metadata->declaredReturnType !== null
            && $types->equals($metadata->declaredReturnType->type, Type::bool())
            && self::predicateContract($metadata, $types, $name);
    }

    /** Match the observed builtin conditional and assertion; do not resolve arbitrary conditional types. */
    private static function predicateContract(FunctionLikeMetadata $metadata, TypeComparator $types, string $name): bool
    {
        $type = $metadata->returnType?->type;
        $conditional = count($type?->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        if (! $conditional instanceof ConditionalType || $conditional->negated
            || count($conditional->subject->atomicTypes) !== 1 || ! $conditional->subject->atomicTypes[0] instanceof VariableType
            || $conditional->subject->atomicTypes[0]->name !== '$value') { return false; }
        foreach ([$type, $conditional->subject, $conditional->target, $conditional->then, $conditional->otherwise] as $part) {
            if (array_filter(get_object_vars($part->flags)) !== []) { return false; }
        }
        $target = $name === 'is_array' ? Type::array(Type::fromAtomics(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()) : Type::string();
        $assertions = $metadata->ifTrueAssertions;
        $assertion = $assertions['$value'][0] ?? null;
        return array_keys($assertions) === ['$value'] && count($assertions['$value']) === 1
            && $assertion instanceof TypeAssertion && $assertion->kind === TypeAssertionKind::IsType
            && $types->equals($assertion->type, $target) && $types->equals($conditional->target, $target)
            && $types->equals($conditional->then, Type::true()) && $types->equals($conditional->otherwise, Type::false());
    }

    private static function exception(Codebase $codebase, TypeComparator $types, Node\Expr\New_ $syntax): bool
    {
        if (! $syntax->class instanceof Node\Name\FullyQualified || strcasecmp($syntax->class->toString(), 'UnexpectedValueException') !== 0) { return false; }
        foreach (['UnexpectedValueException' => 'RuntimeException', 'RuntimeException' => 'Exception', 'Exception' => null] as $name => $parent) {
            $metadata = $codebase->getClass($name);
            if ($metadata === null || $metadata->kind !== ClassLikeKind::Class_ || strcasecmp($metadata->name, $name) !== 0
                || strcasecmp($metadata->originalName, $name) !== 0 || $metadata->hasIncompleteHierarchy()
                || ! $metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->flags->contains(MetadataFlags::USER_DEFINED)
                || $metadata->templates !== [] || $metadata->mixins !== [] || $metadata->usedTraits !== []
                || ($parent === null ? $metadata->directParentClass !== null : strcasecmp($metadata->directParentClass ?? '', $parent) !== 0)) { return false; }
        }
        $constructor = $codebase->getDeclaringMethod('UnexpectedValueException', '__construct');
        if ($constructor === null || $constructor->kind !== FunctionLikeKind::Method
            || $constructor->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            || strcasecmp($constructor->identifier->class ?? '', 'Exception') !== 0 || strcasecmp($constructor->identifier->name, '__construct') !== 0
            || strcasecmp($constructor->name, '__construct') !== 0 || strcasecmp($constructor->originalName, '__construct') !== 0
            || ! $constructor->flags->contains(MetadataFlags::BUILTIN) || $constructor->flags->contains(MetadataFlags::USER_DEFINED)
            || $constructor->flags->contains(MetadataFlags::BY_REFERENCE) || $constructor->static || $constructor->abstract
            || $constructor->visibility !== Visibility::Public || $constructor->templates !== [] || count($constructor->parameters) !== 3
            || $constructor->declaredReturnType !== null || $constructor->returnType !== null) { return false; }
        $expected = [['$message', Type::string(), Type::literalString('')], ['$code', Type::int(), Type::literalInt(0)],
            ['$previous', Type::union(Type::null(), Type::namedObject('Throwable')), Type::null()]];
        foreach ($expected as $index => [$name, $type, $default]) {
            $parameter = $constructor->parameters[$index];
            if ($parameter->name !== $name || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC) || ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
                || $parameter->type === null || $parameter->declaredType === null || $parameter->defaultType === null
                || ! $types->equals($parameter->type->type, $type) || ! $types->equals($parameter->declaredType->type, $type)
                || ! $types->equals($parameter->defaultType->type, $default)) { return false; }
        }
        return true;
    }

    /** @param array<string,mixed> $proof */
    private static function caller(Codebase $codebase, array $proof): bool
    {
        $scope = $proof['scope'];
        $method = $scope instanceof Node\Stmt\ClassMethod;
        if ($method) {
            $class = $codebase->getClass($proof['owner']);
            $syntax = $proof['class'];
            if ($class === null || $class->kind !== ClassLikeKind::Class_ || $class->hasIncompleteHierarchy()
                || strcasecmp($class->name, $proof['owner']) !== 0 || ! $class->flags->contains(MetadataFlags::USER_DEFINED)
                || $class->flags->contains(MetadataFlags::BUILTIN) || $class->templates !== [] || $class->mixins !== []
                || self::path($class->location->file ?? '') !== self::path($proof['file'])
                || ! self::startMatches($syntax, $class->location->span->start) || $class->location->span->end !== $syntax->getEndFilePos() + 1
                || self::path($class->nameLocation?->file ?? '') !== self::path($proof['file'])
                || $class->nameLocation?->span->start !== $syntax->name->getStartFilePos() || $class->nameLocation?->span->end !== $syntax->name->getEndFilePos() + 1) { return false; }
            $metadata = $codebase->getMethod($proof['owner'], $scope->name->name);
        } else { $metadata = $codebase->getFunction($scope->namespacedName->toString()); }
        return $metadata !== null && self::scopeMatches($metadata, $scope, $proof['file'], $proof['owner']);
    }

    private static function scopeMatches(FunctionLikeMetadata $metadata, Node\Stmt\Function_|Node\Stmt\ClassMethod $scope, string $file, ?string $owner): bool
    {
        $name = $scope instanceof Node\Stmt\ClassMethod ? $scope->name->name : $scope->namespacedName->toString();
        if ($metadata->kind !== ($owner === null ? FunctionLikeKind::Function_ : FunctionLikeKind::Method)
            || $metadata->identifier->kind !== ($owner === null ? \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_ : \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method)
            || ($owner === null ? $metadata->identifier->class !== null : strcasecmp($metadata->identifier->class ?? '', $owner) !== 0)
            || strcasecmp($metadata->identifier->name, $name) !== 0 || ! $metadata->flags->contains(MetadataFlags::USER_DEFINED)
            || $metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || $metadata->templates !== [] || $metadata->abstract || $metadata->globalsAccessed !== []
            || $metadata->static !== ($scope instanceof Node\Stmt\ClassMethod && $scope->isStatic())
            || self::path($metadata->location->file ?? '') !== self::path($file) || ! self::startMatches($scope, $metadata->location->span->start)
            || $metadata->location->span->end !== $scope->getEndFilePos() + 1 || self::path($metadata->nameLocation?->file ?? '') !== self::path($file)
            || $metadata->nameLocation?->span->start !== $scope->name->getStartFilePos() || $metadata->nameLocation?->span->end !== $scope->name->getEndFilePos() + 1
            || count($metadata->parameters) !== count($scope->params)) { return false; }
        foreach ($scope->params as $index => $syntax) {
            $parameter = $metadata->parameters[$index];
            if ($parameter->name !== '$'.$syntax->var->name || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC) || self::path($parameter->location->file) !== self::path($file)
                || $parameter->location->span->start !== $syntax->getStartFilePos() || $parameter->location->span->end !== $syntax->getEndFilePos() + 1
                || self::path($parameter->nameLocation->file) !== self::path($file) || $parameter->nameLocation->span->start !== $syntax->var->getStartFilePos()
                || $parameter->nameLocation->span->end !== $syntax->var->getEndFilePos() + 1) { return false; }
        }
        return true;
    }

    private static function startMatches(Node $node, int $start): bool
    {
        if ($start === $node->getStartFilePos()) { return true; }
        foreach ($node->getComments() as $comment) { if ($start === $comment->getStartFilePos()) { return true; } }
        return false;
    }
}
