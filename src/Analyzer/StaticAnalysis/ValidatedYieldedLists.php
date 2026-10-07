<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParent;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Bounded proof of a fresh JSON list checked exhaustively before an optional record copy. */
final class ValidatedYieldedLists implements InitializationHook, CodebaseScanHook
{
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'argv', 'argc', 'http_response_header'];
    /** @var array<string,array{hash:string,proofs:list<array<string,mixed>>}> */
    private array $files = [];
    private bool $started = false;
    private bool $complete = false;
    private bool $failed = false;
    private int $bytes = 0;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->files = [];
        $this->started = $this->complete = $this->failed = false;
        $this->bytes = 0;
    }

    public function getTargets(): array { return ['**']; }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->files = [];
            $this->started = true;
            $this->failed = false;
            $this->bytes = 0;
        } elseif (! $this->started || $this->complete) { $this->failed = true; }
        $this->complete = false;
        foreach ($context->files as $file) {
            $path = self::path($file->path);
            $this->bytes += strlen($file->contents);
            if ($context->cancellation->isCancelled() || $this->failed || $path === '' || isset($this->files[$path])
                || strlen($file->contents) > 2_000_000 || $this->bytes > 64 * 1024 * 1024 || count($this->files) >= 100_000) { $this->failed = true; break; }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $proofs = [];
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem && strtolower($node->key->name) === 'ticks') === null) {
                foreach (self::scopes($nodes) as [$scope, $class]) {
                    if (! self::safeScope($scope)) { continue; }
                    foreach (self::candidates($scope) as $proof) {
                        if (count($proofs) >= 1024) { $this->failed = true; break 2; }
                        $proofs[] = $proof + ['scope' => $scope, 'class' => $class, 'owner' => $class?->namespacedName?->toString(),
                            'file' => $file->path, 'diskFile' => $this->disk($file->path), 'hash' => hash('sha256', $file->contents)];
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
        return $entry !== null && $entry['hash'] === hash('sha256', $contents) ? $entry['proofs'] : [];
    }

    /** @param array<string,mixed> $proof */
    public function contract(Codebase $codebase, TypeComparator $types, array $proof): ?Type
    {
        if (! self::current($proof)) { return null; }
        $metadata = self::caller($codebase, $proof);
        $contract = count($metadata?->returnType?->type->atomicTypes ?? []) === 1 ? $metadata->returnType->type->atomicTypes[0] : null;
        $native = count($metadata?->declaredReturnType?->type->atomicTypes ?? []) === 1 ? $metadata->declaredReturnType->type->atomicTypes[0] : null;
        if ($metadata === null || ! $metadata->hasDocblock || $metadata->returnType?->fromDocblock !== true || $metadata->returnType->inferred
            || $metadata->declaredReturnType?->fromDocblock !== false || $metadata->declaredReturnType->inferred
            || ! $proof['scope']->returnType instanceof Node\Identifier || strtolower($proof['scope']->returnType->name) !== 'iterable'
            || ! $contract instanceof IterableType || $contract->intersections !== null
            || ! $native instanceof IterableType || $native->intersections !== null || ! $types->equals($native->keyType, Type::mixed())
            || ! $types->equals($native->valueType, Type::mixed()) || ! self::decoder($codebase, $types, $proof['decoder'])) { return null; }
        foreach ($proof['calls'] as $call) { if (! self::predicate($codebase, $types, $call)) { return null; } }
        foreach ($proof['throws'] as $exception) { if (! self::exception($codebase, $types, $exception)) { return null; } }
        return $contract->valueType;
    }

    /** @param array<string,mixed> $proof */
    public static function current(array $proof): bool
    {
        clearstatcache(true, $proof['diskFile']);
        $size = @filesize($proof['diskFile']);
        $bytes = $size !== false && $size <= 2_000_000 ? @file_get_contents($proof['diskFile']) : false;
        return $bytes !== false && hash('sha256', $bytes) === $proof['hash'];
    }

    /** @return iterable<array{Node\Stmt\Function_|Node\Stmt\ClassMethod,?Node\Stmt\Class_}> */
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { yield from self::scopes($node->stmts); }
            elseif ($node instanceof Node\Stmt\Function_ && isset($node->namespacedName)) { yield [$node, null]; }
            elseif ($node instanceof Node\Stmt\Class_ && $node->name !== null && isset($node->namespacedName)) {
                foreach ($node->stmts as $member) { if ($member instanceof Node\Stmt\ClassMethod) { yield [$member, $node]; } }
            }
        }
    }

    private static function safeScope(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope): bool
    {
        if ($scope->byRef || $scope->stmts === null) { return false; }
        foreach ($scope->params as $parameter) { if ($parameter->byRef || $parameter->variadic || self::local($parameter->var) === null) { return false; } }
        $finder = new NodeFinder;
        if ($finder->findFirst($scope->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Stmt\TryCatch || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Stmt\Return_ || $node instanceof Node\Expr\YieldFrom || $node instanceof Node\Stmt\ClassLike
            || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Expr\Variable && self::local($node) === null
            || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec || $node instanceof Node\Stmt\Unset_
            || $node instanceof Node\Arg && ($node->byRef || $node->unpack) || $node instanceof Node\ArrayItem && ($node->byRef || $node->unpack)
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef) !== null) { return false; }
        foreach ($finder->find($scope->stmts, static fn (Node $node): bool => $node->getComments() !== []) as $node) {
            foreach ($node->getComments() as $comment) { if (preg_match('/@(?:var|mago-assert|phpstan-assert|psalm-assert)\b/i', $comment->getText())) { return false; } }
        }
        foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name || in_array(strtolower($call->name->getLast()), ['extract', 'compact', 'get_defined_vars', 'parse_str', 'assert'], true)) { return false; }
        }
        return true;
    }

    /** @return iterable<array<string,mixed>> */
    private static function candidates(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope): iterable
    {
        $finder = new NodeFinder;
        foreach ($scope->stmts ?? [] as $outerIndex => $outer) {
            if (! $outer instanceof Node\Stmt\Foreach_ || $outer->byRef || $outer->keyVar !== null
                || ($tree = self::local($outer->expr)) === null || ($row = self::local($outer->valueVar)) === null || $tree === $row) { continue; }
            $yield = $outer->stmts[array_key_last($outer->stmts)] ?? null;
            $branch = $outer->stmts[count($outer->stmts) - 2] ?? null;
            if (! $yield instanceof Node\Stmt\Expression || ! $yield->expr instanceof Node\Expr\Yield_
                || ! $yield->expr->value instanceof Node\Expr\Array_ || count($yield->expr->value->items) !== 1
                || ! ($item = $yield->expr->value->items[0]) instanceof Node\ArrayItem || $item->key !== null || $item->byRef || $item->unpack
                || ($record = self::local($item->value)) === null || ! $branch instanceof Node\Stmt\If_ || $branch->else !== null
                || $branch->elseifs !== [] || ($root = self::nonnull($branch->cond)) === null || count($branch->stmts) !== 2) { continue; }
            [$loop, $copy] = $branch->stmts;
            if (! $loop instanceof Node\Stmt\Foreach_ || $loop->byRef || $loop->keyVar !== null || ! self::variable($loop->expr, $root)
                || ($element = self::local($loop->valueVar)) === null || count($loop->stmts) !== 1
                || ! $copy instanceof Node\Stmt\Expression || ! $copy->expr instanceof Node\Expr\Assign
                || ! $copy->expr->var instanceof Node\Expr\ArrayDimFetch || ! self::variable($copy->expr->var->var, $record)
                || ! $copy->expr->var->dim instanceof Node\Scalar\String_ || ! self::variable($copy->expr->expr, $root)) { continue; }
            $field = $copy->expr->var->dim->value;
            $check = $loop->stmts[0];
            if ($field === '' || ! $check instanceof Node\Stmt\If_ || $check->else !== null || $check->elseifs !== [] || count($check->stmts) !== 1
                || ! $check->cond instanceof Node\Expr\BooleanNot || ! $check->cond->expr instanceof Node\Expr\FuncCall
                || strtolower($check->cond->expr->name instanceof Node\Name ? $check->cond->expr->name->toString() : '') !== 'is_string'
                || count($check->cond->expr->args) !== 1 || ! $check->cond->expr->args[0] instanceof Node\Arg
                || ! self::variable($check->cond->expr->args[0]->value, $element)
                || ! $check->stmts[0] instanceof Node\Stmt\Expression || ! $check->stmts[0]->expr instanceof Node\Expr\Throw_
                || ! $check->stmts[0]->expr->expr instanceof Node\Expr\New_) { continue; }
            $names = [$tree, $row, $root, $element, $record];
            if (count(array_unique($names)) !== 5) { continue; }
            $decodeAssignment = self::assignment($scope, $tree);
            $rootAssignment = self::assignment($scope, $root);
            $recordAssignment = self::assignment($scope, $record);
            if ($decodeAssignment === null || ! $decodeAssignment->expr instanceof Node\Expr\FuncCall
                || strtolower($decodeAssignment->expr->name instanceof Node\Name ? $decodeAssignment->expr->name->toString() : '') !== 'json_decode'
                || $decodeAssignment->getEndFilePos() >= $outer->getStartFilePos()
                || ! in_array($decodeAssignment, array_map(static fn (Node $node): ?Node => $node instanceof Node\Stmt\Expression ? $node->expr : null, array_slice($scope->stmts, 0, $outerIndex)), true)
                || $rootAssignment === null || ! self::extraction($rootAssignment->expr, $row)
                || ! in_array($rootAssignment, array_map(static fn (Node $node): ?Node => $node instanceof Node\Stmt\Expression ? $node->expr : null, $outer->stmts), true)
                || $rootAssignment->getStartFilePos() <= $outer->getStartFilePos() || $rootAssignment->getEndFilePos() >= $branch->getStartFilePos()
                || $recordAssignment === null || ! $recordAssignment->expr instanceof Node\Expr\Array_
                || ! in_array($recordAssignment, array_map(static fn (Node $node): ?Node => $node instanceof Node\Stmt\Expression ? $node->expr : null, $outer->stmts), true)
                || $recordAssignment->getStartFilePos() <= $rootAssignment->getStartFilePos() || $recordAssignment->getEndFilePos() >= $branch->getStartFilePos()) { continue; }
            $allowed = [];
            foreach ([$decodeAssignment->var, $outer->expr, $outer->valueVar, $rootAssignment->var, $recordAssignment->var, $loop->expr,
                $loop->valueVar, $check->cond->expr->args[0]->value, $copy->expr->var->var, $copy->expr->expr, $item->value] as $variable) { $allowed[spl_object_id($variable)] = true; }
            $seenFields = [];
            $valid = true;
            foreach ($recordAssignment->expr->items as $recordItem) {
                if (! $recordItem instanceof Node\ArrayItem || ! $recordItem->key instanceof Node\Scalar\String_ || $recordItem->key->value === $field
                    || isset($seenFields[$recordItem->key->value]) || ! self::freshField($scope, $recordItem->value, $row, $recordAssignment->getStartFilePos(), $names)) { $valid = false; break; }
                $seenFields[$recordItem->key->value] = true;
            }
            if (! $valid) { continue; }
            foreach ($outer->stmts as $statement) {
                if ($statement === $branch || $statement === $yield) { continue; }
                $target = $statement instanceof Node\Stmt\If_ && count($statement->stmts) === 1 && $statement->else === null && $statement->elseifs === []
                    && $statement->stmts[0] instanceof Node\Stmt\Expression && $statement->stmts[0]->expr instanceof Node\Expr\Assign ? $statement->stmts[0]->expr : null;
                if ($target?->var instanceof Node\Expr\ArrayDimFetch && self::variable($target->var->var, $record)) {
                    $key = $target->var->dim;
                    $source = self::local($target->expr);
                    if (! $key instanceof Node\Scalar\String_ || isset($seenFields[$key->value]) || $key->value === $field
                        || $source === null || self::nonnull($statement->cond) !== $source || ! self::freshField($scope, $target->expr, $row, $statement->getStartFilePos(), $names)) { $valid = false; break; }
                    $seenFields[$key->value] = true;
                    $allowed[spl_object_id($target->var->var)] = true;
                }
            }
            if (! $valid) { continue; }
            $calls = $throws = [];
            foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\FuncCall::class) as $call) {
                if ($call->getStartFilePos() < $decodeAssignment->getStartFilePos()) { continue; }
                if ($call === $decodeAssignment->expr) { continue; }
                $name = strtolower($call->name instanceof Node\Name ? $call->name->toString() : '');
                if (! in_array($name, ['is_array', 'array_is_list', 'is_string', 'is_int'], true) || $call->isFirstClassCallable()
                    || count($call->args) !== 1 || ! $call->args[0] instanceof Node\Arg || $call->args[0]->name !== null
                    || self::local($call->args[0]->value) === null) { $valid = false; break; }
                $calls[] = $call;
                $allowed[spl_object_id($call->args[0]->value)] = true;
            }
            foreach ($finder->find($scope->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall) as $call) {
                if ($call->getStartFilePos() >= $decodeAssignment->getStartFilePos()) { $valid = false; break; }
            }
            foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\New_::class) as $new) {
                if ($new->getStartFilePos() >= $decodeAssignment->getStartFilePos()) { $throws[] = $new; }
            }
            foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\BinaryOp\NotIdentical::class) as $comparison) {
                if (self::nonnull($comparison) === $root) { $allowed[spl_object_id(self::isNull($comparison->left) ? $comparison->right : $comparison->left)] = true; }
            }
            foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\ArrayDimFetch::class) as $read) {
                if (self::variable($read->var, $row) && $read->dim instanceof Node\Scalar\String_) { $allowed[spl_object_id($read->var)] = true; }
            }
            foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\Assign::class) as $assignment) {
                if ($assignment->var instanceof Node\Expr\ArrayDimFetch
                    && (self::variable($assignment->var->var, $tree) || self::variable($assignment->var->var, $row))) { $valid = false; break; }
            }
            foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\Variable::class) as $variable) {
                if (in_array($variable->name, $names, true) && ! isset($allowed[spl_object_id($variable)])) { $valid = false; break; }
            }
            foreach ($scope->params as $parameter) { if (in_array($parameter->var->name, $names, true)) { $valid = false; } }
            if ($valid) { yield ['value' => $yield->expr->value, 'field' => $field, 'decoder' => $decodeAssignment->expr, 'calls' => $calls, 'throws' => $throws]; }
        }
    }

    private static function assignment(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope, string $name): ?Node\Expr\Assign
    {
        $nodes = (new NodeFinder)->find($scope->stmts ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\Assign && self::variable($node->var, $name));
        return count($nodes) === 1 ? $nodes[0] : null;
    }

    private static function freshField(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope, Node\Expr $value, string $row, int $before, array $reserved): bool
    {
        $name = self::local($value);
        $assignment = $name === null || in_array($name, $reserved, true) ? null : self::assignment($scope, $name);
        return $assignment !== null && $assignment->getEndFilePos() < $before && self::extraction($assignment->expr, $row);
    }

    private static function extraction(Node\Expr $value, string $row): bool
    {
        return $value instanceof Node\Expr\BinaryOp\Coalesce && self::isNull($value->right)
            && $value->left instanceof Node\Expr\ArrayDimFetch && self::variable($value->left->var, $row) && $value->left->dim instanceof Node\Scalar\String_;
    }

    private static function nonnull(Node\Expr $node): ?string
    {
        return $node instanceof Node\Expr\BinaryOp\NotIdentical
            ? (self::isNull($node->right) ? self::local($node->left) : (self::isNull($node->left) ? self::local($node->right) : null)) : null;
    }

    private static function isNull(Node $node): bool { return $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString()) === 'null'; }
    private static function variable(Node $node, string $name): bool { return $node instanceof Node\Expr\Variable && $node->name === $name; }
    private static function local(Node $node): ?string
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $node->name)
            && ! in_array($node->name, self::RESERVED, true) ? $node->name : null;
    }

    private static function builtin(Codebase $codebase, Node\Expr\FuncCall $call, string $name): ?FunctionLikeMetadata
    {
        if (! $call->name instanceof Node\Name || strcasecmp($call->name->toString(), $name) !== 0 || $call->isFirstClassCallable()) { return null; }
        $fallback = $call->name->getAttribute('namespacedName');
        if (! $call->name instanceof Node\Name\FullyQualified && $fallback instanceof Node\Name && strcasecmp($fallback->toString(), $name) !== 0
            && $codebase->getFunction($fallback->toString()) !== null) { return null; }
        $metadata = $codebase->getFunction($name);
        if ($metadata === null || $metadata->kind !== FunctionLikeKind::Function_ || $metadata->identifier->class !== null
            || $metadata->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_
            || strcasecmp($metadata->identifier->name, $name) !== 0 || ! $metadata->flags->contains(MetadataFlags::BUILTIN)
            || strcasecmp($metadata->name, $name) !== 0 || strcasecmp($metadata->originalName, $name) !== 0
            || $metadata->flags->contains(MetadataFlags::USER_DEFINED) || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || $name !== 'array_is_list' && $metadata->templates !== [] || $metadata->globalsAccessed !== [] || $metadata->whereConstraints !== []) { return null; }
        foreach ($metadata->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->outType !== null || $parameter->closureThisType !== null) { return null; }
        }
        return $metadata;
    }

    private static function decoder(Codebase $codebase, TypeComparator $types, Node\Expr\FuncCall $call): bool
    {
        $metadata = self::builtin($codebase, $call, 'json_decode');
        if ($metadata === null || count($metadata->parameters) !== 4 || $metadata->returnType === null || $metadata->declaredReturnType === null
            || ! $types->equals($metadata->returnType->type, Type::mixed()) || ! $types->equals($metadata->declaredReturnType->type, Type::mixed())
            || count($call->args) < 2 || count($call->args) > 3) { return false; }
        $expected = [['$json', Type::string(), null], ['$associative', Type::union(Type::bool(), Type::null()), Type::null()],
            ['$depth', Type::int(), Type::literalInt(512)], ['$flags', Type::int(), Type::literalInt(0)]];
        foreach ($expected as $index => [$name, $type, $default]) {
            $parameter = $metadata->parameters[$index];
            if ($parameter->name !== $name || $parameter->type === null || $parameter->declaredType === null
                || ! $types->equals($parameter->type->type, $type) || ! $types->equals($parameter->declaredType->type, $type)
                || ($default === null ? $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
                    : ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || $parameter->defaultType === null || ! $types->equals($parameter->defaultType->type, $default))) { return false; }
        }
        if ($metadata->assertions !== [] || $metadata->ifTrueAssertions !== [] || $metadata->ifFalseAssertions !== [] || $metadata->assertionsInferred) { return false; }
        foreach ($call->args as $argument) { if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack) { return false; } }
        if ($call->args[0]->name !== null || self::local($call->args[0]->value) === null || $call->args[1]->name !== null
            || ! $call->args[1]->value instanceof Node\Expr\ConstFetch || strtolower($call->args[1]->value->name->toString()) !== 'true') { return false; }
        if (count($call->args) === 2) { return true; }
        $flag = $call->args[2];
        if ($flag->name?->name !== 'flags' || ! $flag->value instanceof Node\Expr\ConstFetch || $flag->value->name->toString() !== 'JSON_THROW_ON_ERROR') { return false; }
        $fallback = $flag->value->name->getAttribute('namespacedName');
        if (! $flag->value->name instanceof Node\Name\FullyQualified && $fallback instanceof Node\Name && $fallback->toString() !== 'JSON_THROW_ON_ERROR'
            && $codebase->constantExists($fallback->toString())) { return false; }
        $constant = $codebase->getConstant('JSON_THROW_ON_ERROR');
        return $constant !== null && $constant->name === 'JSON_THROW_ON_ERROR' && $constant->flags->contains(MetadataFlags::BUILTIN)
            && ! $constant->flags->contains(MetadataFlags::USER_DEFINED) && $constant->type === null && $constant->inferredType !== null
            && $types->equals($constant->inferredType, Type::literalInt(4194304));
    }

    private static function predicate(Codebase $codebase, TypeComparator $types, Node\Expr\FuncCall $call): bool
    {
        $name = strtolower($call->name->toString());
        $metadata = self::builtin($codebase, $call, $name);
        $parameter = $metadata?->parameters[0] ?? null;
        if ($metadata === null || count($metadata->parameters) !== 1 || $parameter === null || $parameter->type === null || $parameter->declaredType === null
            || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || $metadata->declaredReturnType === null || $metadata->returnType === null
            || ! $types->equals($metadata->declaredReturnType->type, Type::bool())) { return false; }
        if ($name === 'array_is_list') { return self::listPredicate($metadata, $types); }
        foreach ([$metadata->declaredReturnType->type, $parameter->type->type, $parameter->declaredType->type] as $part) {
            if (array_filter(get_object_vars($part->flags)) !== []) { return false; }
        }
        $conditional = count($metadata->returnType->type->atomicTypes) === 1 ? $metadata->returnType->type->atomicTypes[0] : null;
        $target = match ($name) { 'is_string' => Type::string(), 'is_int' => Type::int(), 'is_array' => Type::array(Type::mixed(), Type::mixed()), default => null };
        if (! $conditional instanceof ConditionalType || $conditional->negated || $target === null
            || $parameter->name !== '$value'
            || count($conditional->subject->atomicTypes) !== 1 || ! $conditional->subject->atomicTypes[0] instanceof VariableType
            || $conditional->subject->atomicTypes[0]->name !== $parameter->name || ! $types->equals($parameter->type->type, Type::mixed())
            || ! $types->equals($parameter->declaredType->type, Type::mixed()) || ! $types->equals($conditional->then, Type::true())
            || ! $types->equals($conditional->otherwise, Type::false())) { return false; }
        foreach ([$metadata->returnType->type, $conditional->subject, $conditional->target, $conditional->then, $conditional->otherwise] as $part) {
            if (array_filter(get_object_vars($part->flags)) !== []) { return false; }
        }
        // Broad is_array keys use the SDK's dedicated array-key atom.
        if ($name === 'is_array') { $target = Type::array(Type::fromAtomics(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey)), Type::mixed()); }
        $assertion = $metadata->ifTrueAssertions[$parameter->name][0] ?? null;
        return $types->equals($conditional->target, $target) && $metadata->assertions === [] && $metadata->ifFalseAssertions === []
            && array_keys($metadata->ifTrueAssertions) === [$parameter->name] && count($metadata->ifTrueAssertions[$parameter->name]) === 1
            && $assertion instanceof \Mago\Sdk\Analyzer\Assertion\TypeAssertion && $assertion->kind === \Mago\Sdk\Analyzer\Assertion\TypeAssertionKind::IsType
            && $types->equals($assertion->type, $target) && ! $metadata->assertionsInferred;
    }

    /** Check the observed V-preserving native conditional, rather than resolving arbitrary templates. */
    private static function listPredicate(FunctionLikeMetadata $metadata, TypeComparator $types): bool
    {
        $template = count($metadata->templates) === 1 ? $metadata->templates[0] : null;
        $parameter = $metadata->parameters[0];
        $parent = new GenericParent(GenericParentKind::FunctionLike, '', 'array_is_list');
        $value = Type::fromAtomic(new GenericParameterType('V', Type::mixed(), $parent, null));
        $key = Type::fromAtomics(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey));
        $conditional = count($metadata->returnType->type->atomicTypes) === 1 ? $metadata->returnType->type->atomicTypes[0] : null;
        if ($template === null || $template->name !== 'V' || $template->default !== null || $template->readonly
            || $template->variance !== Variance::Invariant || ! self::sameParent($template->definingEntity, $parent)
            || ! $types->equals($template->constraint, Type::mixed()) || $parameter->name !== '$array'
            || ! $types->equals($parameter->declaredType->type, Type::array($key, Type::mixed()))
            || ! $types->equals($parameter->type->type, Type::array($key, $value))
            || ! $conditional instanceof ConditionalType || $conditional->negated || count($conditional->subject->atomicTypes) !== 1
            || ! $conditional->subject->atomicTypes[0] instanceof VariableType || $conditional->subject->atomicTypes[0]->name !== '$array'
            || ! $types->equals($conditional->target, Type::list($value)) || ! $types->equals($conditional->then, Type::true())
            || ! $types->equals($conditional->otherwise, Type::false()) || $metadata->assertions !== [] || $metadata->ifFalseAssertions !== []
            || array_keys($metadata->ifTrueAssertions) !== ['$array'] || count($metadata->ifTrueAssertions['$array']) !== 1 || $metadata->assertionsInferred) { return false; }
        $target = $conditional->target->atomicTypes[0] ?? null;
        $generic = $target instanceof ListType && count($target->elementType->atomicTypes) === 1 ? $target->elementType->atomicTypes[0] : null;
        if (! $generic instanceof GenericParameterType || $generic->name !== 'V' || $generic->intersections !== null
            || ! self::sameParent($generic->definingEntity, $parent) || ! $types->equals($generic->constraint, Type::mixed())) { return false; }
        $assertion = $metadata->ifTrueAssertions['$array'][0];
        if (! $assertion instanceof \Mago\Sdk\Analyzer\Assertion\TypeAssertion || $assertion->kind !== \Mago\Sdk\Analyzer\Assertion\TypeAssertionKind::IsType
            || ! $types->equals($assertion->type, Type::list($value))) { return false; }
        foreach ([$metadata->declaredReturnType->type, $metadata->returnType->type, $parameter->type->type, $parameter->declaredType->type,
            $conditional->subject, $conditional->target, $conditional->then, $conditional->otherwise, $target->elementType, $template->constraint, $generic->constraint] as $part) {
            $flags = get_object_vars($part->flags);
            unset($flags['populated']);
            if (array_filter($flags) !== []) { return false; }
        }
        return true;
    }

    private static function sameParent(GenericParent $actual, GenericParent $expected): bool
    {
        return $actual->kind === $expected->kind && $actual->name === $expected->name && $actual->member === $expected->member;
    }

    private static function exception(Codebase $codebase, TypeComparator $types, Node\Expr\New_ $syntax): bool
    {
        if (! $syntax->class instanceof Node\Name\FullyQualified || $syntax->class->toString() !== 'RuntimeException' || count($syntax->args) > 1) { return false; }
        foreach ($syntax->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->byRef || $argument->unpack || ! $argument->value instanceof Node\Scalar\String_) { return false; }
        }
        foreach (['RuntimeException' => 'Exception', 'Exception' => null] as $name => $parent) {
            $class = $codebase->getClass($name);
            if ($class === null || $class->kind !== ClassLikeKind::Class_ || strcasecmp($class->name, $name) !== 0 || $class->hasIncompleteHierarchy()
                || strcasecmp($class->originalName, $name) !== 0
                || ! $class->flags->contains(MetadataFlags::BUILTIN) || $class->flags->contains(MetadataFlags::USER_DEFINED)
                || ($parent === null ? $class->directParentClass !== null : strcasecmp($class->directParentClass ?? '', $parent) !== 0)
                || $class->templates !== [] || $class->mixins !== [] || $class->usedTraits !== []) { return false; }
        }
        $constructor = $codebase->getDeclaringMethod('RuntimeException', '__construct');
        if ($constructor === null || $constructor->kind !== FunctionLikeKind::Method || $constructor->identifier->class !== 'Exception'
            || $constructor->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            || $constructor->identifier->name !== '__construct' || $constructor->visibility !== Visibility::Public || $constructor->static || $constructor->abstract
            || $constructor->name !== '__construct' || $constructor->originalName !== '__construct' || $constructor->globalsAccessed !== []
            || $constructor->whereConstraints !== [] || $constructor->assertions !== [] || $constructor->ifTrueAssertions !== [] || $constructor->ifFalseAssertions !== []
            || ! $constructor->flags->contains(MetadataFlags::BUILTIN) || $constructor->flags->contains(MetadataFlags::USER_DEFINED)
            || $constructor->flags->contains(MetadataFlags::BY_REFERENCE) || $constructor->templates !== [] || count($constructor->parameters) !== 3
            || $constructor->declaredReturnType !== null || $constructor->returnType !== null) { return false; }
        foreach ([['$message', Type::string(), Type::literalString('')], ['$code', Type::int(), Type::literalInt(0)], ['$previous', Type::union(Type::null(), Type::namedObject('Throwable')), Type::null()]] as $index => [$name, $type, $default]) {
            $parameter = $constructor->parameters[$index];
            if ($parameter->name !== $name || $parameter->type === null || $parameter->declaredType === null || $parameter->defaultType === null
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || ! $types->equals($parameter->type->type, $type)
                || ! $types->equals($parameter->declaredType->type, $type) || ! $types->equals($parameter->defaultType->type, $default)) { return false; }
        }
        return true;
    }

    /** @param array<string,mixed> $proof */
    private static function caller(Codebase $codebase, array $proof): ?FunctionLikeMetadata
    {
        $scope = $proof['scope'];
        $owner = $proof['owner'];
        if ($owner !== null) {
            $class = $codebase->getClass($owner);
            $syntax = $proof['class'];
            if ($class === null || $class->kind !== ClassLikeKind::Class_ || $class->hasIncompleteHierarchy() || strcasecmp($class->name, $owner) !== 0
                || ! $class->flags->contains(MetadataFlags::USER_DEFINED) || $class->flags->contains(MetadataFlags::BUILTIN)
                || $class->templates !== [] || $class->mixins !== [] || self::path($class->location->file ?? '') !== self::path($proof['file'])
                || ! self::start($syntax, $class->location->span->start) || $class->location->span->end !== $syntax->getEndFilePos() + 1
                || self::path($class->nameLocation?->file ?? '') !== self::path($proof['file'])
                || $class->nameLocation?->span->start !== $syntax->name->getStartFilePos() || $class->nameLocation?->span->end !== $syntax->name->getEndFilePos() + 1) { return null; }
        }
        $name = $owner === null ? $scope->namespacedName->toString() : $scope->name->name;
        $metadata = $owner === null ? $codebase->getFunction($name) : $codebase->getMethod($owner, $name);
        if ($metadata === null || $metadata->kind !== ($owner === null ? FunctionLikeKind::Function_ : FunctionLikeKind::Method)
            || $metadata->identifier->kind !== ($owner === null ? \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_ : \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method)
            || ($owner === null ? $metadata->identifier->class !== null : strcasecmp($metadata->identifier->class ?? '', $owner) !== 0)
            || strcasecmp($metadata->identifier->name, $name) !== 0 || ! $metadata->flags->contains(MetadataFlags::USER_DEFINED)
            || $metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || $metadata->templates !== [] || $metadata->whereConstraints !== [] || $metadata->abstract || $metadata->globalsAccessed !== []
            || $metadata->assertions !== [] || $metadata->ifTrueAssertions !== [] || $metadata->ifFalseAssertions !== [] || $metadata->assertionsInferred
            || $metadata->static !== ($scope instanceof Node\Stmt\ClassMethod && $scope->isStatic())
            || self::path($metadata->location->file ?? '') !== self::path($proof['file']) || ! self::start($scope, $metadata->location->span->start)
            || $metadata->location->span->end !== $scope->getEndFilePos() + 1 || self::path($metadata->nameLocation?->file ?? '') !== self::path($proof['file'])
            || $metadata->nameLocation?->span->start !== $scope->name->getStartFilePos() || $metadata->nameLocation?->span->end !== $scope->name->getEndFilePos() + 1
            || count($metadata->parameters) !== count($scope->params)) { return null; }
        foreach ($scope->params as $index => $syntax) {
            $parameter = $metadata->parameters[$index];
            if ($parameter->name !== '$'.$syntax->var->name || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->outType !== null || $parameter->closureThisType !== null || self::path($parameter->location->file) !== self::path($proof['file'])
                || $parameter->location->span->start !== $syntax->getStartFilePos() || $parameter->location->span->end !== $syntax->getEndFilePos() + 1
                || self::path($parameter->nameLocation->file) !== self::path($proof['file']) || $parameter->nameLocation->span->start !== $syntax->var->getStartFilePos()
                || $parameter->nameLocation->span->end !== $syntax->var->getEndFilePos() + 1) { return null; }
        }
        return $metadata;
    }

    private static function start(Node $node, int $offset): bool
    {
        if ($node->getStartFilePos() === $offset) { return true; }
        foreach ($node->getComments() as $comment) { if ($comment->getStartFilePos() === $offset) { return true; } }
        return false;
    }
    public static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $path)) { $path = substr($path, 4); }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
    private function disk(string $path): string
    {
        $path = self::path($path);
        return str_starts_with($path, '/') || preg_match('~^[a-z]:/~i', $path) ? $path : self::path($this->root).'/'.$path;
    }
}
