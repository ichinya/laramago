<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Preserve a conditional native array fact across an immediately following ternary. */
final class ConditionalArrayGuards implements CodebaseScanHook, InitializationHook
{
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header', 'argv', 'argc'];
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
        if ($context->firstBatch) {
            $this->files = [];
            $this->failed = false;
        }
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
            $functions = self::functions($nodes);
            $proofs = [];
            foreach (self::blocks($nodes) as [$scope, $statements]) {
                foreach (self::candidates($scope, $statements, $functions) as $proof) {
                    $proofs[] = $proof + ['scope' => $scope, 'file' => $file->path,
                        'diskFile' => $this->diskFile($file->path), 'hash' => hash('sha256', $file->contents)];
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

    /**
     * Read script-local source candidates under PHPStan's default lexical assignment policy.
     * The temporary frame is used only by the source interpreter; it has no native identity.
     * Explicit references and dynamic storage retain their refusal behavior.
     *
     * @return list<array<string, mixed>>
     */
    public static function lexicalScriptProofs(string $contents): array
    {
        if (strlen($contents) > 1024 * 1024) { return []; }
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse(
                (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []);
        } catch (\PhpParser\Error) { return []; }
        if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool =>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Variable && ! is_string($node->name)
            || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Expr\ClosureUse && $node->byRef) !== null) { return []; }
        $functions = self::functions($nodes);
        $proofs = [];
        foreach (self::blocks($nodes) as [$scope, $statements]) {
            if ($scope !== null) { continue; }
            foreach ($statements as $index => $statement) {
                if (self::selection($statements[$index + 1] ?? null, $functions) === null) { continue; }
                $prefix = array_slice($statements, 0, $index + 2);
                $frame = new Node\Stmt\Function_('__laramago_lexical_frame', ['stmts' => $prefix]);
                foreach (self::candidates($frame, $prefix, $functions) as $proof) {
                    $proof['scope'] = null;
                    $proofs[] = $proof;
                }
            }
        }
        return $proofs;
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
        if ($proof['scope'] !== null && ! self::functionMatches($codebase, $proof['scope'], $proof['file'])) { return false; }
        if ($proof['consumer'] !== null && ! self::functionMatches($codebase, $proof['consumer'], $proof['file'])) { return false; }
        foreach ($proof['calls'] as $call) {
            $name = strtolower($call->name->toString());
            if (! self::nativeFunction($codebase, $call, $name)) { return false; }
        }
        foreach ($proof['constants'] as $syntax) {
            $namespace = $syntax->name->getAttribute('namespacedName');
            if (! $syntax->name instanceof Node\Name\FullyQualified && $namespace instanceof Node\Name
                && $namespace->toString() !== 'JSON_THROW_ON_ERROR' && $codebase->getConstant($namespace->toString()) !== null) { return false; }
            $metadata = $codebase->getConstant('JSON_THROW_ON_ERROR');
            $type = $metadata?->inferredType ?? $metadata?->type?->type;
            $atom = $type?->atomicTypes[0] ?? null;
            if ($metadata === null || $metadata->name !== 'JSON_THROW_ON_ERROR' || ! $metadata->flags->contains(MetadataFlags::BUILTIN)
                || count($type?->atomicTypes ?? []) !== 1 || ! $atom instanceof ScalarType || $atom->kind !== ScalarTypeKind::Integer
                || ! $atom->refinement instanceof IntegerType || $atom->refinement->getLiteralValue() !== 4194304) { return false; }
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

    /** Only unconditional declarations can be resolved before the argument without an opaque callee. */
    private static function functions(array $nodes): array
    {
        $functions = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { $functions += self::functions($node->stmts); }
            elseif ($node instanceof Node\Stmt\Function_) { $functions[strtolower($node->namespacedName->toString())] = $node; }
        }
        return $functions;
    }

    /** @return iterable<array{?Node\Stmt\Function_, array<Node\Stmt>}> */
    private static function blocks(array $statements, ?Node\Stmt\Function_ $scope = null): iterable
    {
        yield [$scope, $statements];
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) { yield from self::blocks($statement->stmts); }
            elseif ($statement instanceof Node\Stmt\Function_ && $scope === null) { yield from self::blocks($statement->stmts, $statement); }
            elseif ($statement instanceof Node\Stmt\TryCatch) { yield from self::blocks($statement->stmts, $scope); }
        }
    }

    /** @return iterable<array<string, mixed>> */
    private static function candidates(?Node\Stmt\Function_ $scope, array $statements, array $functions): iterable
    {
        $first = [];
        if ($scope !== null) {
            if (! self::localScope($scope)) { return; }
            foreach ((new NodeFinder)->findInstanceOf([$scope], Node\Expr\Variable::class) as $variable) {
                $first[$variable->name] = min($first[$variable->name] ?? PHP_INT_MAX, $variable->getStartFilePos());
            }
        }
        foreach ($statements as $index => $guard) {
            if (! self::throwing($guard) || ! $guard->cond instanceof Node\Expr\BinaryOp\BooleanAnd) { continue; }
            $key = self::nullTest($guard->cond->left, false);
            $checks = self::disjuncts($guard->cond->right);
            if ($key === null || count($checks) !== 3 || ! $checks[0] instanceof Node\Expr\BooleanNot
                || ! $checks[0]->expr instanceof Node\Expr\Isset_ || count($checks[0]->expr->vars) !== 1) { continue; }
            $field = $checks[0]->expr->vars[0];
            $root = $field instanceof Node\Expr\ArrayDimFetch ? self::local($field->var) : null;
            if ($root === null || $root === $key || ! $field->dim instanceof Node\Scalar\String_
                || ! self::notNative($checks[1], 'is_array', [$field])
                || ! self::notNative($checks[2], 'array_key_exists', [new Node\Expr\Variable($key), $field])) { continue; }
            $statement = $statements[$index + 1] ?? null;
            $selected = self::selection($statement, $functions);
            if ($selected === null || self::nullTest($selected['ternary']->cond, true) !== $key
                || ! $selected['ternary']->else instanceof Node\Expr\ArrayDimFetch
                || ! self::same($selected['ternary']->else->var, $field)
                || self::local($selected['ternary']->else->dim) !== $key) { continue; }
            $origin = $rootGuard = $keyGuard = null;
            foreach (array_slice($statements, 0, $index) as $priorIndex => $prior) {
                $assignment = $prior instanceof Node\Stmt\Expression && $prior->expr instanceof Node\Expr\Assign ? $prior->expr : null;
                if ($assignment !== null && self::local($assignment->var) === $root) {
                    $origin = self::json($assignment->expr) ? $priorIndex : null;
                    $rootGuard = null;
                }
                if (self::throwing($prior) && self::notNative($prior->cond, 'is_array', [new Node\Expr\Variable($root)])) { $rootGuard = $priorIndex; }
                if (self::throwing($prior) && $prior->cond instanceof Node\Expr\BinaryOp\BooleanAnd
                    && self::nullTest($prior->cond->left, false) === $key
                    && self::notNative($prior->cond->right, 'is_string', [new Node\Expr\Variable($key)])) { $keyGuard = $priorIndex; }
            }
            if ($origin === null || $rootGuard === null || $keyGuard === null || $origin >= $rootGuard || $keyGuard >= $index) { continue; }
            $initializer = $statements[$origin]->expr;
            if ($scope !== null && ($first[$root] ?? null) !== $initializer->var->getStartFilePos()) { continue; }
            $calls = [];
            $arrays = [];
            $nonObjects = [];
            $valid = true;
            foreach (array_slice($statements, $origin, $index - $origin + 1) as $offset => $prior) {
                $position = $origin + $offset;
                if ((new NodeFinder)->findFirst([$prior], static fn (Node $node): bool =>
                    preg_match('/@(?:phpstan-|psalm-|mago-)?var\b/', $node->getDocComment()?->getText() ?? '') === 1) !== null) { $valid = false; break; }
                if ($position === $rootGuard) { $arrays[$root] = true; }
                if ($prior instanceof Node\Stmt\Expression && $prior->expr instanceof Node\Expr\Assign
                    && self::local($prior->expr->var) !== null) {
                    $name = self::local($prior->expr->var);
                    if ($name === $root && $position !== $origin || $name === $key && $position > $keyGuard
                        || ! self::safe($prior->expr->expr, $arrays, $calls)
                        || $position > $rootGuard && ($scope === null
                            || ! isset($nonObjects[$name]) && ($first[$name] ?? null) !== $prior->expr->var->getStartFilePos()
                            || ! self::nonObject($prior->expr->expr, $arrays, $nonObjects))) { $valid = false; break; }
                    if ($position === $origin || self::nonObject($prior->expr->expr, $arrays, $nonObjects)) { $nonObjects[$name] = true; }
                    else { unset($nonObjects[$name]); }
                    if ($prior->expr->expr instanceof Node\Expr\FuncCall && self::callName($prior->expr->expr, 'getopt')) { $arrays[$name] = true; }
                    elseif ($name !== $root) { unset($arrays[$name]); }
                } elseif (self::throwing($prior)) {
                    if (! self::safe($prior->cond, $arrays, $calls)) { $valid = false; break; }
                } else { $valid = false; break; }
            }
            if (! $valid || count(array_filter($calls, static fn (Node\Expr\FuncCall $call): bool => self::callName($call, 'json_decode'))) !== 1) { continue; }
            // The failed branch throws; its exception expression never runs on the proven continuation.
            $constants = (new NodeFinder)->find($calls, static fn (Node $node): bool =>
                $node instanceof Node\Expr\ConstFetch && $node->name->toString() === 'JSON_THROW_ON_ERROR');
            yield ['access' => $selected['ternary']->else, 'calls' => $calls, 'constants' => $constants, 'consumer' => $selected['consumer']];
        }
    }

    private static function selection(?Node\Stmt $statement, array $functions): ?array
    {
        $expression = $statement instanceof Node\Stmt\Return_ ? $statement->expr
            : ($statement instanceof Node\Stmt\Expression ? $statement->expr : null);
        if ($expression instanceof Node\Expr\Assign) {
            if (self::local($expression->var) === null) { return null; }
            $expression = $expression->expr;
        }
        $consumer = null;
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name
            && count($expression->args) === 1 && self::plain($expression->args)) {
            $name = $expression->name->getAttribute('namespacedName');
            $consumer = $functions[strtolower(($name instanceof Node\Name ? $name : $expression->name)->toString())] ?? null;
            if ($consumer === null) { return null; }
            $expression = $expression->args[0]->value;
        }
        return $expression instanceof Node\Expr\Ternary && $expression->if !== null ? ['ternary' => $expression, 'consumer' => $consumer] : null;
    }

    /** A closed expression grammar excludes calls that could invalidate top-level globals. */
    private static function safe(Node $expression, array $arrays, array &$calls): bool
    {
        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_) { return true; }
        if ($expression instanceof Node\Expr\Variable) { return self::local($expression) !== null; }
        if ($expression instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($expression->name->toString()), ['true', 'false', 'null'], true)
                || $expression->name->toString() === 'JSON_THROW_ON_ERROR';
        }
        if ($expression instanceof Node\Expr\ArrayDimFetch) {
            return self::local($expression->var) !== null && isset($arrays[self::local($expression->var)])
                && $expression->dim instanceof Node\Scalar\String_;
        }
        if ($expression instanceof Node\Expr\Array_) {
            foreach ($expression->items as $item) {
                if ($item === null || $item->byRef || $item->unpack || $item->key !== null && ! self::safe($item->key, $arrays, $calls)
                    || ! self::safe($item->value, $arrays, $calls)) { return false; }
            }
            return true;
        }
        if ($expression instanceof Node\Expr\BooleanNot) { return self::safe($expression->expr, $arrays, $calls); }
        if ($expression instanceof Node\Expr\Isset_) {
            foreach ($expression->vars as $variable) { if (! self::safe($variable, $arrays, $calls)) { return false; } }
            return true;
        }
        if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd || $expression instanceof Node\Expr\BinaryOp\BooleanOr
            || $expression instanceof Node\Expr\BinaryOp\Identical || $expression instanceof Node\Expr\BinaryOp\NotIdentical
            || $expression instanceof Node\Expr\BinaryOp\Coalesce) {
            return self::safe($expression->left, $arrays, $calls) && self::safe($expression->right, $arrays, $calls);
        }
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name
            && in_array(strtolower($expression->name->toString()), ['json_decode', 'is_array', 'is_string', 'array_key_exists', 'getopt'], true)
            && self::plain($expression->args, true)) {
            $name = strtolower($expression->name->toString());
            if ($name === 'getopt' && ! self::literalOptions($expression)) { return false; }
            foreach ($expression->args as $argument) { if (! self::safe($argument->value, $arrays, $calls)) { return false; } }
            $calls[] = $expression;
            return true;
        }
        return false;
    }

    /** Older global aliases cannot turn the guarded root into an object through these assignments. */
    private static function nonObject(Node $expression, array $arrays, array $nonObjects): bool
    {
        if ($expression instanceof Node\Scalar\String_ || $expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_) { return true; }
        if ($expression instanceof Node\Expr\ConstFetch) { return in_array(strtolower($expression->name->toString()), ['true', 'false', 'null'], true); }
        if ($expression instanceof Node\Expr\Variable) { return self::local($expression) !== null && isset($nonObjects[self::local($expression)]); }
        if ($expression instanceof Node\Expr\Array_) {
            foreach ($expression->items as $item) {
                if ($item === null || $item->byRef || $item->unpack || $item->key !== null && ! self::nonObject($item->key, $arrays, $nonObjects)
                    || ! self::nonObject($item->value, $arrays, $nonObjects)) { return false; }
            }
            return true;
        }
        if ($expression instanceof Node\Expr\BooleanNot
            || $expression instanceof Node\Expr\BinaryOp\Identical || $expression instanceof Node\Expr\BinaryOp\NotIdentical
            || $expression instanceof Node\Expr\BinaryOp\BooleanAnd || $expression instanceof Node\Expr\BinaryOp\BooleanOr) { return true; }
        if ($expression instanceof Node\Expr\ArrayDimFetch) { return self::local($expression->var) !== null && isset($arrays[self::local($expression->var)]); }
        if ($expression instanceof Node\Expr\BinaryOp\Coalesce) { return self::nonObject($expression->left, $arrays, $nonObjects) && self::nonObject($expression->right, $arrays, $nonObjects); }
        return $expression instanceof Node\Expr\FuncCall && in_array(strtolower($expression->name->toString()), ['is_array', 'is_string', 'array_key_exists', 'getopt'], true);
    }

    /** Fresh function locals cannot have hidden reference bindings or a previous object destructor. */
    private static function localScope(Node\Stmt\Function_ $scope): bool
    {
        return ! $scope->byRef && (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
            $node !== $scope && ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike)
            || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\ShellExec
            || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
            || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Param && ($node->byRef || $node->variadic)
            || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Expr\Variable && (self::local($node) === null)
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str', 'mb_parse_str', 'compact', 'get_defined_vars', 'func_get_args', 'func_get_arg', 'call_user_func', 'call_user_func_array'], true))) === null;
    }

    private static function literalOptions(Node\Expr\FuncCall $call): bool
    {
        if (count($call->args) < 1 || count($call->args) > 2 || ! self::plain($call->args)
            || ! $call->args[0]->value instanceof Node\Scalar\String_) { return false; }
        if (count($call->args) === 1) { return true; }
        $options = $call->args[1]->value;
        if (! $options instanceof Node\Expr\Array_) { return false; }
        foreach ($options->items as $item) {
            if ($item === null || $item->key !== null || $item->byRef || $item->unpack || ! $item->value instanceof Node\Scalar\String_) { return false; }
        }
        return true;
    }

    private static function json(Node $expression): bool
    {
        return $expression instanceof Node\Expr\FuncCall && self::callName($expression, 'json_decode')
            && self::plain($expression->args, true) && isset($expression->args[1]) && $expression->args[1]->name === null
            && $expression->args[1]->value instanceof Node\Expr\ConstFetch && strtolower($expression->args[1]->value->name->toString()) === 'true';
    }

    private static function throwing(?Node $statement): bool
    {
        return $statement instanceof Node\Stmt\If_ && $statement->else === null && $statement->elseifs === [] && count($statement->stmts) === 1
            && $statement->stmts[0] instanceof Node\Stmt\Expression && $statement->stmts[0]->expr instanceof Node\Expr\Throw_;
    }

    private static function nullTest(Node $expression, bool $equal): ?string
    {
        return ($equal ? $expression instanceof Node\Expr\BinaryOp\Identical : $expression instanceof Node\Expr\BinaryOp\NotIdentical)
            && $expression->right instanceof Node\Expr\ConstFetch && strtolower($expression->right->name->toString()) === 'null'
            ? self::local($expression->left) : null;
    }

    private static function notNative(Node $expression, string $name, array $arguments): bool
    {
        if (! $expression instanceof Node\Expr\BooleanNot || ! $expression->expr instanceof Node\Expr\FuncCall
            || ! self::callName($expression->expr, $name) || ! self::plain($expression->expr->args)
            || count($expression->expr->args) !== count($arguments)) { return false; }
        foreach ($arguments as $index => $argument) { if (! self::same($expression->expr->args[$index]->value, $argument)) { return false; } }
        return true;
    }

    private static function same(Node $left, Node $right): bool
    {
        return self::local($left) !== null && self::local($left) === self::local($right)
            || $left instanceof Node\Expr\ArrayDimFetch && $right instanceof Node\Expr\ArrayDimFetch
            && self::same($left->var, $right->var) && $left->dim instanceof Node\Scalar\String_
            && $right->dim instanceof Node\Scalar\String_ && $left->dim->value === $right->dim->value;
    }

    private static function local(?Node $node): ?string
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name) && ! in_array($node->name, self::RESERVED, true) ? $node->name : null;
    }

    private static function disjuncts(Node $expression): array
    {
        return $expression instanceof Node\Expr\BinaryOp\BooleanOr ? [...self::disjuncts($expression->left), ...self::disjuncts($expression->right)] : [$expression];
    }

    private static function callName(Node\Expr\FuncCall $call, string $name): bool
    {
        return $call->name instanceof Node\Name && strcasecmp($call->name->toString(), $name) === 0;
    }

    private static function plain(array $arguments, bool $named = false): bool
    {
        foreach ($arguments as $argument) { if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || ! $named && $argument->name !== null) { return false; } }
        return true;
    }

    private static function nativeFunction(Codebase $codebase, Node\Expr\FuncCall $call, string $expected): bool
    {
        if (! $call->name instanceof Node\Name\FullyQualified) {
            $namespaced = $call->name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), $expected) !== 0
                && $codebase->getFunction($namespaced->toString()) !== null) { return false; }
        }
        $metadata = $codebase->getFunction($expected);
        if ($metadata === null || ! $metadata->flags->contains(MetadataFlags::BUILTIN)
            || $metadata->identifier->class !== null || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || strcasecmp($metadata->identifier->name, $expected) !== 0) { return false; }
        $bound = [];
        foreach ($call->args as $index => $argument) {
            $parameter = $metadata->parameters[$index] ?? null;
            if ($argument->name !== null) {
                $parameter = null;
                foreach ($metadata->parameters as $candidate) { if ($candidate->name === '$'.$argument->name->name) { $parameter = $candidate; break; } }
            }
            if ($parameter === null || isset($bound[$parameter->name]) || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)) { return false; }
            $bound[$parameter->name] = true;
        }
        return true;
    }

    private static function functionMatches(Codebase $codebase, Node\Stmt\Function_ $syntax, string $file): bool
    {
        $metadata = $codebase->getFunction($syntax->namespacedName->toString());
        if ($syntax->byRef || $metadata === null || self::path($metadata->location->file ?? '') !== self::path($file)
            || strcasecmp($metadata->identifier->name, $syntax->namespacedName->toString()) !== 0
            || $metadata->identifier->class !== null || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || $metadata->nameLocation?->span->start !== $syntax->name->getStartFilePos()
            || $metadata->nameLocation?->span->end !== $syntax->name->getEndFilePos() + 1
            || $metadata->location->span->start > $syntax->getStartFilePos()
            || $metadata->location->span->end !== $syntax->getEndFilePos() + 1
            || count($metadata->parameters) !== count($syntax->params)) { return false; }
        foreach ($syntax->params as $index => $parameter) {
            if ($parameter->byRef || $parameter->variadic || ! is_string($parameter->var->name)
                || $metadata->parameters[$index]->name !== '$'.$parameter->var->name
                || $metadata->parameters[$index]->nameLocation->span->start !== $parameter->var->getStartFilePos()
                || $metadata->parameters[$index]->nameLocation->span->end !== $parameter->var->getEndFilePos() + 1
                || $metadata->parameters[$index]->flags->contains(MetadataFlags::BY_REFERENCE)
                || $metadata->parameters[$index]->flags->contains(MetadataFlags::VARIADIC)) { return false; }
        }
        return true;
    }
}
