<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind as IdentifierKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Prove a fresh scalar record reconstructed after an exhaustive native discriminator guard. */
final class ReconstructedArrayShapes implements CodebaseScanHook, InitializationHook
{
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header', 'argv', 'argc'];
    private const NATIVE_SIBLINGS = ['array_map', 'array_unique', 'array_column', 'count', 'is_array', 'is_string', 'is_int', 'array_is_list', 'in_array'];
    /** @var array<string, array{hash: string, proofs: list<array<string, mixed>>}> */
    private array $files = [];
    private bool $complete = false;
    private bool $failed = false;

    public function __construct(private readonly string $root = '') {}

    public function initialize(InitializationContext $context): void
    {
        $this->files = [];
        $this->complete = $this->failed = false;
    }

    public function getTargets(): array { return ['**']; }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->files = []; $this->failed = false; }
        $this->complete = false;
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            $path = self::path($file->path);
            if ($this->failed || isset($this->files[$path]) || strlen($file->contents) > 2_000_000 || count($this->files) >= 100_000) {
                $this->failed = true;
                break;
            }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) {
                $this->failed = true;
                break;
            }
            $proofs = [];
            // Tick declarations defer: the proof does not model callbacks at statement boundaries.
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem
                && strtolower($node->key->name) === 'ticks') === null) {
                foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                    if ($class->name === null || $class->namespacedName === null) { continue; }
                    $constructor = $class->getMethod('__construct');
                    if ($constructor === null || $constructor->stmts === null || $constructor->byRef) { continue; }
                    foreach ($class->getMethods() as $scope) {
                        if ($scope === $constructor || $scope->stmts === null || ! self::localScope($scope)) { continue; }
                        foreach (self::candidates($scope, $constructor, $class) as $proof) {
                            $proofs[] = $proof + ['scope' => $scope, 'constructor' => $constructor, 'class' => $class,
                                'owner' => $class->namespacedName->toString(), 'file' => $file->path,
                                'diskFile' => $this->diskFile($file->path), 'hash' => hash('sha256', $file->contents)];
                        }
                    }
                }
            }
            $this->files[$path] = ['hash' => hash('sha256', $file->contents), 'proofs' => $proofs];
        }
        if ($this->failed) { $this->files = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    /** @return list<array<string, mixed>> */
    public function proofs(string $file, string $contents): array
    {
        $entry = $this->complete ? ($this->files[self::path($file)] ?? null) : null;
        return $entry !== null && hash('sha256', $contents) === $entry['hash'] ? $entry['proofs'] : [];
    }

    /** @param array<string, mixed> $proof */
    public static function current(array $proof): bool
    {
        $size = @filesize($proof['diskFile']);
        $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($proof['diskFile']) : false;
        return $contents !== false && hash('sha256', $contents) === $proof['hash'];
    }

    /** @param array<string, mixed> $proof */
    public static function valid(Codebase $codebase, array $proof): bool
    {
        $metadata = $codebase->getClass($proof['owner']);
        $class = $proof['class'];
        if ($metadata === null || $metadata->kind !== ClassLikeKind::Class_ || strcasecmp($metadata->name, $proof['owner']) !== 0
            || self::path($metadata->location->file ?? '') !== self::path($proof['file'])
            || ! self::startMatches($class, $metadata->location->span->start)
            || $metadata->location->span->end !== $class->getEndFilePos() + 1
            || $metadata->nameLocation?->span->start !== $class->name->getStartFilePos()
            || $metadata->nameLocation?->span->end !== $class->name->getEndFilePos() + 1
            || self::path($metadata->nameLocation?->file ?? '') !== self::path($proof['file'])
            || ! self::methodMatches($codebase, $proof['owner'], $proof['scope'], $proof['file'])
            || ! self::methodMatches($codebase, $proof['owner'], $proof['constructor'], $proof['file'])) { return false; }
        foreach ($proof['calls'] as $call) {
            if (! self::nativeFunction($codebase, $call)) { return false; }
        }
        foreach ($proof['siblings'] as $call) {
            if (! self::sibling($codebase, $call, $proof['owner'])) { return false; }
        }
        return true;
    }

    public static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }

    public static function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) { $file = substr($file, 4); }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }

    private function diskFile(string $file): string
    {
        $file = self::path($file);
        return str_starts_with($file, '/') || preg_match('~^[a-z]:/~i', $file) === 1 ? $file
            : self::path($this->root !== '' ? $this->root : (getcwd() ?: '.')).'/'.$file;
    }

    /** @return iterable<array<string, mixed>> */
    private static function candidates(Node\Stmt\ClassMethod $scope, Node\Stmt\ClassMethod $constructor, Node\Stmt\Class_ $class): iterable
    {
        $statements = $scope->stmts;
        foreach ($statements as $index => $guard) {
            if (! self::throwing($guard)) { continue; }
            $checks = self::disjuncts($guard->cond);
            if (count($checks) < 3 || ! self::notNative($checks[0], 'is_array')) { continue; }
            $source = self::local($checks[0]->expr->args[0]->value);
            if ($source === null || ! self::notNative($checks[1], 'in_array')) { continue; }
            $membership = $checks[1]->expr;
            $tag = self::field($membership->args[0]->value, $source, true);
            $tags = self::literalList($membership->args[1]->value ?? null);
            if ($tag === null || $tags === null || count($membership->args) !== 3 || ! self::boolean($membership->args[2]->value, true)) { continue; }
            $dependent = $checks[2];
            if (! $dependent instanceof Node\Expr\BinaryOp\BooleanAnd) { continue; }
            $required = self::tagTest($dependent->left, $source, $tag);
            $requirements = self::disjuncts($dependent->right);
            if ($required === null || ! in_array($required, $tags, true) || ! self::notNative($requirements[0], 'is_int')) { continue; }
            $identifier = self::field($requirements[0]->expr->args[0]->value, $source, true);
            if ($identifier === null || $identifier === $tag) { continue; }
            $calls = [$checks[0]->expr, $membership, $requirements[0]->expr];
            $defined = self::defined($scope, array_slice($statements, 0, $index));
            foreach (array_slice($requirements, 1) as $condition) {
                if (! self::safe($condition, $source, [$tag, $identifier], $defined)) { continue 2; }
            }
            foreach (array_slice($checks, 3) as $condition) {
                if (! self::safe($condition, $source, [$tag], $defined)) { continue 2; }
            }
            $assignment = self::assignment($statements[$index + 1] ?? null, $source);
            $ternary = $assignment?->expr;
            if (! $ternary instanceof Node\Expr\Ternary || $ternary->if === null) { continue; }
            $selected = self::tagTest($ternary->cond, $source, $tag);
            if ($selected === null || ! in_array($selected, $tags, true)) { continue; }
            $other = $tags[0] === $selected ? $tags[1] : $tags[0];
            $first = self::record($ternary->if, $source, $tag, $identifier, $selected, $required, false);
            $second = self::record($ternary->else, $source, $tag, $identifier, $other, $required, false);
            $generalFirst = self::record($ternary->if, $source, $tag, $identifier, $selected, $required, true);
            $generalSecond = self::record($ternary->else, $source, $tag, $identifier, $other, $required, true);
            if ($first === null || $second === null || $generalFirst === null || $generalSecond === null) { continue; }
            $origin = self::assignment($statements[$index - 1] ?? null, $source);
            if ($origin === null || ! self::fresh($scope, $source, $origin) || self::documented($scope, $source)) { continue; }
            foreach (array_slice($statements, $index + 2, null, true) as $followingIndex => $following) {
                $call = $following instanceof Node\Stmt\Return_ ? $following->expr : null;
                if (! $call instanceof Node\Expr\New_ || ! $call->class instanceof Node\Name
                    || strtolower($call->class->toString()) !== 'self' || ! self::plain($call->args)) { continue; }
                foreach ($call->args as $position => $argument) {
                    if (self::local($argument->value) !== $source
                        || ! self::isolated($scope, $source, [$origin->var, $guard->cond, $assignment, $argument->value])) { continue; }
                    $siblings = (new NodeFinder)->find(array_slice($statements, $index + 2, $followingIndex - $index - 1), static fn (Node $node): bool =>
                        $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall
                        || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall);
                    yield ['call' => $call, 'argument' => $argument, 'position' => $position, 'identifier' => $identifier,
                        'shape' => Type::union($first, $second), 'generalShape' => Type::union($generalFirst, $generalSecond), 'calls' => $calls, 'siblings' => $siblings];
                }
            }
        }
    }

    /** Only literal strings and the dependent checked integer can enter a rebuilt branch. */
    private static function record(Node $expression, string $source, string $tag, string $identifier, string $variant, string $required, bool $general): ?Type
    {
        if (! $expression instanceof Node\Expr\Array_) { return null; }
        $items = [];
        $seen = [];
        foreach ($expression->items as $item) {
            if ($item === null || $item->byRef || $item->unpack || ! $item->key instanceof Node\Scalar\String_
                || isset($seen[$item->key->value])) { return null; }
            $key = $item->key->value;
            if ($key === $tag && $item->value instanceof Node\Scalar\String_ && $item->value->value === $variant) {
                $type = Type::literalString($variant);
            } elseif ($key === $identifier && $variant === $required && self::field($item->value, $source) === $identifier) {
                $type = $general ? Type::mixed() : Type::int();
            } else { return null; }
            $seen[$key] = true;
            $items[] = new ArrayItem(new ArrayKey(ArrayKeyKind::String, $key), false, $type);
        }
        if (! isset($seen[$tag]) || count($items) !== ($variant === $required ? 2 : 1)) { return null; }
        return Type::fromAtomic(new KeyedArrayType($items, null, null, true));
    }

    private static function localScope(Node\Stmt\ClassMethod $scope): bool
    {
        return ! $scope->byRef && (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\ShellExec
            || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Param && ($node->byRef || $node->variadic)
            || $node instanceof Node\Arg && ($node->byRef || $node->unpack)
            || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Expr\Variable && self::local($node) === null
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str', 'mb_parse_str', 'compact', 'get_defined_vars', 'func_get_args', 'func_get_arg', 'call_user_func', 'call_user_func_array'], true))) === null;
    }

    private static function fresh(Node\Stmt\ClassMethod $scope, string $source, Node\Expr\Assign $origin): bool
    {
        $first = (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $source);
        return $first === $origin->var;
    }

    /** An explicit local annotation keeps its existing analyzer priority. */
    private static function documented(Node\Stmt\ClassMethod $scope, string $source): bool
    {
        return (new NodeFinder)->findFirst([$scope], static function (Node $node) use ($source): bool {
            foreach ($node->getComments() as $comment) {
                preg_match_all('/@(?:var|mago-var|phpstan-var|psalm-var)([^\r\n]*)/', $comment->getText(), $matches);
                foreach ($matches[1] as $annotation) {
                    if (preg_match('/\$'.preg_quote($source, '/').'\b/', $annotation) === 1
                        || ! str_contains($annotation, '$') && (new NodeFinder)->findFirst([$node], static fn (Node $child): bool =>
                            $child instanceof Node\Expr\Variable && $child->name === $source) !== null) { return true; }
                }
            }
            return false;
        }) !== null;
    }

    /** Every mention of the local cell belongs to the closed proof or its one by-value consumer. */
    private static function isolated(Node\Stmt\ClassMethod $scope, string $source, array $allowed): bool
    {
        foreach ((new NodeFinder)->findInstanceOf([$scope], Node\Expr\Variable::class) as $variable) {
            if ($variable->name !== $source) { continue; }
            foreach ($allowed as $node) {
                if ($variable->getStartFilePos() >= $node->getStartFilePos() && $variable->getEndFilePos() <= $node->getEndFilePos()) { continue 2; }
            }
            return false;
        }
        return true;
    }

    /** No callback, object coercion, or array access outside the already checked primitive fields. */
    private static function safe(Node $expression, string $source, array $fields, array $defined): bool
    {
        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_) { return true; }
        if ($expression instanceof Node\Expr\ConstFetch) { return in_array(strtolower($expression->name->toString()), ['true', 'false', 'null'], true); }
        if ($expression instanceof Node\Expr\Variable) { return self::local($expression) !== null && isset($defined[self::local($expression)]) && self::local($expression) !== $source; }
        if ($expression instanceof Node\Expr\ArrayDimFetch) { return in_array(self::field($expression, $source), $fields, true); }
        if ($expression instanceof Node\Expr\BooleanNot) { return self::safe($expression->expr, $source, $fields, $defined); }
        if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd || $expression instanceof Node\Expr\BinaryOp\BooleanOr
            || $expression instanceof Node\Expr\BinaryOp\Identical || $expression instanceof Node\Expr\BinaryOp\NotIdentical) {
            return self::safe($expression->left, $source, $fields, $defined) && self::safe($expression->right, $source, $fields, $defined);
        }
        if ($expression instanceof Node\Expr\BinaryOp\Smaller || $expression instanceof Node\Expr\BinaryOp\SmallerOrEqual
            || $expression instanceof Node\Expr\BinaryOp\Greater || $expression instanceof Node\Expr\BinaryOp\GreaterOrEqual) {
            return $expression->left instanceof Node\Scalar\Int_ && $expression->right instanceof Node\Scalar\Int_
                || $expression->left instanceof Node\Scalar\Int_ && in_array(self::field($expression->right, $source), $fields, true)
                || $expression->right instanceof Node\Scalar\Int_ && in_array(self::field($expression->left, $source), $fields, true);
        }
        return false;
    }

    /** An undefined local read can invoke an error handler that changes referenced input fields. */
    private static function defined(Node\Stmt\ClassMethod $scope, array $prefix): array
    {
        $defined = [];
        foreach ($scope->params as $parameter) {
            if (is_string($parameter->var->name)) { $defined[$parameter->var->name] = true; }
        }
        foreach ($prefix as $statement) {
            foreach ((new NodeFinder)->findInstanceOf([$statement], Node\Stmt\Unset_::class) as $unset) {
                foreach ($unset->vars as $variable) {
                    if (self::local($variable) !== null) { unset($defined[self::local($variable)]); }
                }
            }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                && self::local($statement->expr->var) !== null) { $defined[self::local($statement->expr->var)] = true; }
        }
        return $defined;
    }

    private static function assignment(?Node $statement, string $source): ?Node\Expr\Assign
    {
        return $statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
            && self::local($statement->expr->var) === $source ? $statement->expr : null;
    }

    private static function throwing(Node $statement): bool
    {
        return $statement instanceof Node\Stmt\If_ && $statement->else === null && $statement->elseifs === [] && count($statement->stmts) === 1
            && $statement->stmts[0] instanceof Node\Stmt\Expression && $statement->stmts[0]->expr instanceof Node\Expr\Throw_;
    }

    private static function notNative(Node $expression, string $name): bool
    {
        return $expression instanceof Node\Expr\BooleanNot && $expression->expr instanceof Node\Expr\FuncCall
            && $expression->expr->name instanceof Node\Name && strcasecmp($expression->expr->name->toString(), $name) === 0
            && self::plain($expression->expr->args) && count($expression->expr->args) === ($name === 'in_array' ? 3 : 1);
    }

    private static function field(Node $expression, string $source, bool $nullable = false): ?string
    {
        if ($nullable) {
            if (! $expression instanceof Node\Expr\BinaryOp\Coalesce || ! $expression->right instanceof Node\Expr\ConstFetch
                || strtolower($expression->right->name->toString()) !== 'null') { return null; }
            $expression = $expression->left;
        }
        return $expression instanceof Node\Expr\ArrayDimFetch && self::local($expression->var) === $source
            && $expression->dim instanceof Node\Scalar\String_ && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $expression->dim->value) === 1
            ? $expression->dim->value : null;
    }

    private static function tagTest(Node $expression, string $source, string $tag): ?string
    {
        return $expression instanceof Node\Expr\BinaryOp\Identical && self::field($expression->left, $source) === $tag
            && $expression->right instanceof Node\Scalar\String_ ? $expression->right->value : null;
    }

    private static function literalList(?Node $expression): ?array
    {
        if (! $expression instanceof Node\Expr\Array_ || count($expression->items) !== 2) { return null; }
        $values = [];
        foreach ($expression->items as $item) {
            if ($item === null || $item->byRef || $item->unpack || $item->key !== null || ! $item->value instanceof Node\Scalar\String_) { return null; }
            $values[] = $item->value->value;
        }
        return $values[0] !== $values[1] ? $values : null;
    }

    private static function boolean(Node $expression, bool $expected): bool
    {
        return $expression instanceof Node\Expr\ConstFetch && strtolower($expression->name->toString()) === ($expected ? 'true' : 'false');
    }

    private static function local(?Node $node): ?string
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name) && ! in_array($node->name, self::RESERVED, true) ? $node->name : null;
    }

    private static function disjuncts(Node $expression): array
    {
        return $expression instanceof Node\Expr\BinaryOp\BooleanOr ? [...self::disjuncts($expression->left), ...self::disjuncts($expression->right)] : [$expression];
    }

    private static function plain(array $arguments): bool
    {
        foreach ($arguments as $argument) { if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null) { return false; } }
        return true;
    }

    private static function nativeFunction(Codebase $codebase, Node\Expr\FuncCall $call): bool
    {
        $expected = strtolower($call->name->toString());
        if (! $call->name instanceof Node\Name\FullyQualified) {
            $namespaced = $call->name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), $expected) !== 0
                && $codebase->getFunction($namespaced->toString()) !== null) { return false; }
        }
        $metadata = $codebase->getFunction($expected);
        if ($metadata === null || $metadata->kind !== FunctionLikeKind::Function_ || ! $metadata->flags->contains(MetadataFlags::BUILTIN)
            || $metadata->identifier->class !== null || $metadata->identifier->kind !== IdentifierKind::Function_
            || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || strcasecmp($metadata->identifier->name, $expected) !== 0 || count($metadata->parameters) < count($call->args)) { return false; }
        foreach ($call->args as $index => $argument) {
            $parameter = $metadata->parameters[$index];
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)) { return false; }
        }
        return true;
    }

    /** Other calls need their own PHP frame, or a bounded native dispatch that cannot expose caller locals. */
    private static function sibling(Codebase $codebase, Node $call, string $owner): bool
    {
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            $name = $call->name->toString();
            $namespaced = $call->name->getAttribute('namespacedName');
            $metadata = ! $call->name instanceof Node\Name\FullyQualified && $namespaced instanceof Node\Name
                ? $codebase->getFunction($namespaced->toString()) : null;
            $resolved = $metadata !== null ? $namespaced->toString() : $name;
            $metadata ??= $codebase->getFunction($name);
            return $metadata !== null && $metadata->kind === FunctionLikeKind::Function_ && $metadata->identifier->class === null
                && $metadata->identifier->kind === IdentifierKind::Function_
                && strcasecmp($metadata->identifier->name, $resolved) === 0
                && ($metadata->flags->contains(MetadataFlags::USER_DEFINED) && ! $metadata->flags->contains(MetadataFlags::BUILTIN)
                    || in_array(strtolower($name), self::NATIVE_SIBLINGS, true) && self::nativeFunction($codebase, $call));
        }
        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
            $class = strtolower($call->class->toString()) === 'self' ? $owner : $call->class->toString();
            $metadata = $codebase->getMethod($class, $call->name->toString());
            return $metadata !== null && $metadata->kind === FunctionLikeKind::Method
                && $metadata->identifier->kind === IdentifierKind::Method
                && strcasecmp($metadata->identifier->class ?? '', $class) === 0
                && strcasecmp($metadata->identifier->name, $call->name->toString()) === 0
                && $metadata->flags->contains(MetadataFlags::USER_DEFINED) && ! $metadata->flags->contains(MetadataFlags::BUILTIN);
        }
        return false;
    }

    private static function startMatches(Node $syntax, int $start): bool
    {
        if ($start === $syntax->getStartFilePos()) { return true; }
        foreach ($syntax->getComments() as $comment) { if ($start === $comment->getStartFilePos()) { return true; } }
        return false;
    }

    private static function methodMatches(Codebase $codebase, string $owner, Node\Stmt\ClassMethod $syntax, string $file): bool
    {
        $metadata = $codebase->getMethod($owner, $syntax->name->toString());
        if ($syntax->byRef || $metadata === null || ! self::methodIdentity($metadata, $owner, $syntax, $file)
            || count($metadata->parameters) !== count($syntax->params)) { return false; }
        foreach ($syntax->params as $index => $parameter) {
            $bound = $metadata->parameters[$index];
            if ($parameter->byRef || $parameter->variadic || ! is_string($parameter->var->name)
                || $bound->name !== '$'.$parameter->var->name
                || $bound->nameLocation->span->start !== $parameter->var->getStartFilePos()
                || $bound->nameLocation->span->end !== $parameter->var->getEndFilePos() + 1
                || self::path($bound->nameLocation->file) !== self::path($file)
                || self::path($bound->location->file) !== self::path($file)
                || $bound->location->span->start !== $parameter->getStartFilePos()
                || $bound->location->span->end !== $parameter->getEndFilePos() + 1
                || $bound->flags->contains(MetadataFlags::BY_REFERENCE) || $bound->flags->contains(MetadataFlags::VARIADIC)) { return false; }
        }
        return true;
    }

    private static function methodIdentity(FunctionLikeMetadata $metadata, string $owner, Node\Stmt\ClassMethod $syntax, string $file): bool
    {
        return self::path($metadata->location->file ?? '') === self::path($file)
            && $metadata->kind === FunctionLikeKind::Method
            && $metadata->identifier->kind === IdentifierKind::Method
            && strcasecmp($metadata->identifier->class ?? '', $owner) === 0
            && strcasecmp($metadata->identifier->name, $syntax->name->toString()) === 0
            && ! $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            && $metadata->static === $syntax->isStatic()
            && $metadata->constructor === (strtolower($syntax->name->toString()) === '__construct')
            && $metadata->nameLocation?->span->start === $syntax->name->getStartFilePos()
            && $metadata->nameLocation?->span->end === $syntax->name->getEndFilePos() + 1
            && self::path($metadata->nameLocation?->file ?? '') === self::path($file)
            && self::startMatches($syntax, $metadata->location->span->start)
            && $metadata->location->span->end === $syntax->getEndFilePos() + 1;
    }
}
