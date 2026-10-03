<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** Recover actual element parents without assuming that opaque XML parameters are nonempty. */
final class SimpleXmlProvenance implements CodebaseScanHook, InitializationHook
{
    private const XML = 'SimpleXMLElement';

    /** @var array<string, array<string, mixed>|null> */
    private array $properties = [];
    /** @var array<string, array{hash: string, proofs: list<array<string, mixed>>}> */
    private array $files = [];
    private bool $complete = false;
    private bool $failed = false;

    public function __construct(private readonly string $root = '') {}

    public function initialize(InitializationContext $context): void
    {
        $this->properties = $this->files = [];
        $this->complete = $this->failed = false;
    }

    public function getTargets(): array
    {
        return ['**'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->properties = $this->files = [];
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
            $proofs = [];
            $candidates = [];
            foreach (self::scopes($nodes) as [$owner, $scope]) {
                $proof = $this->analyse($scope);
                if ($proof === null) {
                    continue;
                }
                $proof += ['owner' => $owner, 'scope' => $scope, 'file' => $file->path,
                    'diskFile' => $this->diskFile($file->path), 'hash' => hash('sha256', $file->contents)];
                $proofs[] = $proof;
                foreach ($proof['properties'] as $key => $property) {
                    $candidates[$key] = ['proof' => $proof, 'property' => $property];
                }
            }
            $path = self::path($file->path);
            if (isset($this->files[$path])) {
                $this->failed = true;
                break;
            }
            $this->files[$path] = ['hash' => hash('sha256', $file->contents), 'proofs' => $proofs];
            // Provider requests contain a span but no source file. All accesses,
            // including unsupported dynamic and nullsafe ones, reserve their spans.
            foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool =>
                $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) as $access) {
                $key = self::key($access->name);
                $this->properties[$key] = array_key_exists($key, $this->properties) ? null : ($candidates[$key] ?? null);
                if (count($this->properties) > 100_000) {
                    $this->failed = true;
                    break;
                }
            }
        }
        if ($this->failed) {
            $this->properties = $this->files = [];
        }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    /** @return array<string, mixed>|null */
    public function property(Span $span): ?array
    {
        return $this->complete ? ($this->properties[$span->start.':'.$span->end] ?? null) : null;
    }

    /** @return list<array<string, mixed>> */
    public function proofs(string $file, string $contents): array
    {
        $entry = $this->complete ? ($this->files[self::path($file)] ?? null) : null;
        return $entry !== null && hash('sha256', $contents) === $entry['hash'] ? $entry['proofs'] : [];
    }

    /** Validate source identity and every native dispatch used by one proof.
     * @param array<string, mixed> $proof
     */
    public static function valid(Codebase $codebase, array $proof): bool
    {
        $scope = $proof['scope'];
        $owner = $proof['owner'];
        $name = $scope->name->toString();
        $caller = $owner === null ? $codebase->getFunction($name) : $codebase->getDeclaringMethod($owner, $name);
        if ($owner === null && isset($scope->namespacedName)) {
            $caller = $codebase->getFunction($scope->namespacedName->toString());
        }
        if ($caller === null || self::path($caller->location->file ?? '') !== self::path($proof['file'])
            || strcasecmp($caller->identifier->class ?? '', $owner ?? '') !== 0
            || strcasecmp($caller->identifier->name, $owner === null && isset($scope->namespacedName) ? $scope->namespacedName->toString() : $name) !== 0
            || $caller->nameLocation?->span->start !== $scope->name->getStartFilePos()
            || $caller->nameLocation?->span->end !== $scope->name->getEndFilePos() + 1
            || $caller->location->span->start > $scope->getStartFilePos()
            || $caller->location->span->end !== $scope->getEndFilePos() + 1
            || $caller->flags->contains(MetadataFlags::BY_REFERENCE) || $caller->globalsAccessed !== []
            || count($caller->parameters) !== count($scope->params)) {
            return false;
        }
        foreach ($scope->params as $index => $syntax) {
            $parameter = $caller->parameters[$index];
            if ($parameter->name !== '$'.$syntax->var->name
                || $parameter->nameLocation->span->start !== $syntax->var->getStartFilePos()
                || $parameter->nameLocation->span->end !== $syntax->var->getEndFilePos() + 1
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)) {
                return false;
            }
            if (! $syntax->type instanceof Node\Identifier || strtolower($syntax->type->name) !== 'string') {
                continue;
            }
            $type = $caller->parameters[$index]->type->type ?? null;
            $atom = $type?->atomicTypes[0] ?? null;
            $string = $atom instanceof ScalarType && $atom->kind === ScalarTypeKind::String ? $atom->refinement : null;
            if (count($type?->atomicTypes ?? []) !== 1 || ! $string instanceof StringType || $string->literalKind !== StringLiteralKind::General
                || $string->literalValue !== null || $string->numeric || $string->nonEmpty || $string->truthy || $string->callable
                || $string->casing !== StringCasing::Unspecified) {
                return false;
            }
        }
        $xml = $codebase->getClass(self::XML);
        $countable = $codebase->getInterface('Countable');
        if ($xml === null || strcasecmp($xml->name, self::XML) !== 0 || $xml->hasIncompleteHierarchy() || ! $xml->flags->contains(MetadataFlags::BUILTIN)
            || $countable === null || $countable->hasIncompleteHierarchy() || ! $countable->flags->contains(MetadataFlags::BUILTIN)
            || ! in_array('countable', array_map(strtolower(...), $xml->parentInterfaces), true)) {
            return false;
        }
        foreach ($proof['properties'] as $property) {
            $name = '$'.$property->name->name;
            if ($codebase->getDeclaringProperty(self::XML, $name) !== null
                || $codebase->getDeclaringMagicProperty(self::XML, $name) !== null
                || $codebase->getMagicProperty(self::XML, $name) !== null) {
                return false;
            }
        }
        foreach ($proof['functions'] as [$syntax, $expected]) {
            if (! self::nativeFunction($codebase, $syntax, $expected)) {
                return false;
            }
        }
        foreach ($proof['constants'] as $syntax) {
            $namespaced = $syntax->getAttribute('namespacedName');
            if (! $syntax instanceof Node\Name\FullyQualified && $namespaced instanceof Node\Name
                && $namespaced->toString() !== 'LIBXML_NONET' && $codebase->getConstant($namespaced->toString()) !== null) {
                return false;
            }
            $constant = $codebase->getConstant('LIBXML_NONET');
            $type = $constant?->inferredType ?? $constant?->type?->type;
            $atom = $type?->atomicTypes[0] ?? null;
            if ($constant === null || $constant->name !== 'LIBXML_NONET' || ! $constant->flags->contains(MetadataFlags::BUILTIN)
                || count($type?->atomicTypes ?? []) !== 1 || ! $atom instanceof ScalarType || $atom->kind !== ScalarTypeKind::Integer
                || ! $atom->refinement instanceof IntegerType || $atom->refinement->getLiteralValue() !== 2048) {
                return false;
            }
        }
        foreach ($proof['methods'] as $name) {
            $method = $codebase->getDeclaringMethod(self::XML, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::XML) !== 0
                || strcasecmp($method->identifier->name, $name) !== 0 || ! $method->flags->contains(MetadataFlags::BUILTIN)
                || $method->static || $method->abstract || $method->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return false;
            }
        }
        return true;
    }

    public static function key(Node $node): string
    {
        return $node->getStartFilePos().':'.($node->getEndFilePos() + 1);
    }

    /** @param array<string, mixed> $proof */
    public static function current(array $proof): bool
    {
        $size = @filesize($proof['diskFile']);
        $bytes = $size !== false && $size <= 2_000_000 ? @file_get_contents($proof['diskFile']) : false;
        return $bytes !== false && hash('sha256', $bytes) === $proof['hash'];
    }

    private function diskFile(string $file): string
    {
        $file = self::path($file);
        return str_starts_with($file, '/') || preg_match('~^[a-z]:/~i', $file) === 1
            ? $file : self::path($this->root !== '' ? $this->root : (getcwd() ?: '.')).'/'.$file;
    }

    public static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $path) === 1) {
            $path = substr($path, 4);
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /** @return iterable<array{?string, Node\Stmt\Function_|Node\Stmt\ClassMethod}> */
    private static function scopes(array $nodes): iterable
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::scopes($node->stmts);
            } elseif ($node instanceof Node\Stmt\Function_) {
                yield [null, $node];
            } elseif ($node instanceof Node\Stmt\ClassLike && isset($node->namespacedName)) {
                foreach ($node->getMethods() as $method) {
                    yield [$node->namespacedName->toString(), $method];
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function analyse(Node\Stmt\Function_|Node\Stmt\ClassMethod $scope): ?array
    {
        if ($scope->stmts === null || $scope->byRef) {
            return null;
        }
        $finder = new NodeFinder;
        $unsafe = $finder->findFirst([$scope], static fn (Node $node): bool =>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\ShellExec
            || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom || $node instanceof Node\Stmt\Global_
            || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Stmt\Break_ || $node instanceof Node\Stmt\Continue_
            || $node instanceof Node\Param && ($node->byRef || $node->variadic)
            || $node instanceof Node\Expr\Variable && ! is_string($node->name)
            || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['eval', 'extract', 'parse_str', 'mb_parse_str', 'compact', 'get_defined_vars', 'func_get_args', 'func_get_arg', 'call_user_func', 'call_user_func_array'], true)));
        if ($unsafe !== null) {
            return null;
        }
        (new NodeTraverser(new ParentConnectingVisitor))->traverse([$scope]);
        $first = [];
        foreach ($finder->findInstanceOf([$scope], Node\Expr\Variable::class) as $variable) {
            $first[$variable->name] = min($first[$variable->name] ?? PHP_INT_MAX, $variable->getStartFilePos());
        }
        $documentedLocals = $finder->findFirst([$scope], static fn (Node $node): bool =>
            preg_match('/@(?:phpstan-|psalm-|mago-)?var\b/', $node->getDocComment()?->getText() ?? '') === 1) !== null;
        $proof = ['properties' => [], 'attributes' => [], 'arguments' => [], 'comparisons' => [], 'functions' => [], 'methods' => [], 'constants' => [],
            'documentedLocals' => $documentedLocals, 'supportedCounts' => []];
        foreach ($finder->findInstanceOf([$scope], Node\Stmt\If_::class) as $guard) {
            if ($guard->else !== null || $guard->elseifs !== [] || count($guard->stmts) !== 1
                || ! $guard->stmts[0] instanceof Node\Stmt\Expression || ! $guard->stmts[0]->expr instanceof Node\Expr\Throw_) {
                continue;
            }
            foreach (self::disjuncts($guard->cond) as $condition) {
                $call = self::countComparison($condition);
                if ($call !== null) {
                    $proof['supportedCounts'][self::key($call)] = true;
                }
            }
        }
        $inputs = [];
        foreach ($scope->params as $parameter) {
            if ($parameter->type instanceof Node\Identifier && strtolower($parameter->type->name) === 'string') {
                $inputs[$parameter->var->name] = true;
            }
        }
        $state = ['variables' => [], 'present' => [], 'inputs' => $inputs];
        return $this->statements($scope->stmts, $state, $first, $proof) && $proof['properties'] !== [] ? $proof : null;
    }

    /** Structured flow deliberately admits only straight statements, throwing guards,
     * parse-only try/finally, and guarded by-value XPath iteration.
     */
    private function statements(array $statements, array &$state, array $first, array &$proof): bool
    {
        foreach ($statements as $statement) {
            $this->restrictInputs($statement, $state, $proof);
            if ($statement instanceof Node\Stmt\Nop) {
                continue;
            }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable) {
                $assignment = $statement->expr;
                $name = $assignment->var->name;
                $origin = $this->origin($assignment->expr, $state, $proof);
                if ($origin !== null) {
                    if (! self::local($name) || isset($state['variables'][$name]) || $first[$name] !== $assignment->var->getStartFilePos()
                        || $assignment->expr instanceof Node\Expr\Variable || ! $this->inspect($assignment->expr, $state, $proof, true)) {
                        return false;
                    }
                    $state['variables'][$name] = $origin;
                    continue;
                }
                if (isset($state['variables'][$name])) {
                    return false;
                }
                $state['inputs'][$name] = $assignment->expr instanceof Node\Expr\FuncCall
                    && self::local($name) && $first[$name] === $assignment->var->getStartFilePos()
                    && self::functionName($assignment->expr, 'file_get_contents') && self::plainArguments($assignment->expr->args);
                if ($state['inputs'][$name]) {
                    $proof['functions'][] = [$assignment->expr->name, 'file_get_contents'];
                }
            }
            if ($statement instanceof Node\Stmt\Expression || $statement instanceof Node\Stmt\Return_) {
                if ($statement->expr !== null && ! $this->inspect($statement->expr, $state, $proof)) {
                    return false;
                }
            } elseif ($statement instanceof Node\Stmt\If_) {
                if ($statement->else !== null || $statement->elseifs !== [] || count($statement->stmts) !== 1
                    || ! $statement->stmts[0] instanceof Node\Stmt\Expression
                    || ! $statement->stmts[0]->expr instanceof Node\Expr\Throw_
                    || ! $this->inspect($statement->cond, $state, $proof)
                    || ! $this->inspect($statement->stmts[0]->expr, $state, $proof)) {
                    return false;
                }
                foreach (self::disjuncts($statement->cond) as $condition) {
                    self::excludeFalse($condition, $state);
                    if ($condition instanceof Node\Expr\BooleanNot && $condition->expr instanceof Node\Expr\FuncCall
                        && self::functionName($condition->expr, 'is_array') && count($condition->expr->args) === 1
                        && $condition->expr->args[0]->value instanceof Node\Expr\Variable) {
                        $name = $condition->expr->args[0]->value->name;
                        if (($state['variables'][$name]['kind'] ?? null) === 'list') {
                            $state['variables'][$name]['guarded'] = true;
                        }
                    }
                    $call = self::countComparison($condition);
                    $value = $call === null ? null : $this->xml($call->args[0]->value, $state);
                    if ($value === null || $value['kind'] === 'list') {
                        continue;
                    }
                    if ($value['kind'] !== 'nullable' && ! ($value['maybeFalse'] ?? false) && ($value['unknownInput'] ?? false)) {
                        $fact = ['node' => $condition, 'call' => $call, 'expression' => self::expression($call->args[0]->value),
                            'known' => $value['count'] ?? null];
                        $proof['comparisons'][self::key($condition)] = $fact;
                        foreach ((new NodeFinder)->find([$statement->cond], static fn (Node $node): bool =>
                            $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr) as $outer) {
                            if ($outer->getStartFilePos() === $condition->getStartFilePos()) {
                                $proof['comparisons'][self::key($outer)] = $fact;
                            }
                        }
                    }
                    $state['present'][$value['path']] = 1;
                }
            } elseif ($statement instanceof Node\Stmt\TryCatch) {
                if ($statement->catches !== [] || $statement->finally === null || count($statement->stmts) !== 1
                    || ! $statement->stmts[0] instanceof Node\Stmt\Expression
                    || ! $statement->stmts[0]->expr instanceof Node\Expr\Assign
                    || $this->root($statement->stmts[0]->expr->expr, $proof) === null
                    || ! $this->statements($statement->stmts, $state, $first, $proof)
                    || ! $this->statements($statement->finally->stmts, $state, $first, $proof)) {
                    return false;
                }
            } elseif ($statement instanceof Node\Stmt\Foreach_) {
                $list = $this->xml($statement->expr, $state);
                if ($statement->byRef || $statement->keyVar !== null || ! $statement->valueVar instanceof Node\Expr\Variable
                    || $list === null || $list['kind'] !== 'list' || ! ($list['guarded'] ?? false)
                    || ! self::local($statement->valueVar->name)
                    || isset($state['variables'][$statement->valueVar->name])
                    || $first[$statement->valueVar->name] !== $statement->valueVar->getStartFilePos()) {
                    return false;
                }
                $body = $state;
                $body['variables'][$statement->valueVar->name] = ['kind' => 'node', 'path' => '$'.$statement->valueVar->name,
                    'unknownInput' => $list['unknownInput'] ?? false, 'input' => $list['input'] ?? null];
                if (! $this->statements($statement->stmts, $body, $first, $proof)) {
                    return false;
                }
                foreach (array_diff_key($body['variables'], $state['variables']) as $name => $value) {
                    // An empty foreach does not establish its value or derived locals.
                    // Reserve them nevertheless so later aliases/mutations cannot escape.
                    $state['variables'][$name] = ['kind' => 'nullable', 'path' => $value['path']];
                }
                foreach ($state['inputs'] as $name => $unknown) {
                    $state['inputs'][$name] = $unknown && ($body['inputs'][$name] ?? false);
                    if (! $state['inputs'][$name]) {
                        foreach ($state['variables'] as &$value) {
                            if (($value['input'] ?? null) === $name) {
                                $value['unknownInput'] = false;
                            }
                        }
                        unset($value);
                    }
                }
            } else {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    private function origin(Node\Expr $expression, array $state, array &$proof): ?array
    {
        $root = $this->root($expression, $proof);
        if ($root !== null) {
            $input = $expression->args[0]->value;
            return ['kind' => 'node', 'path' => '@'.self::key($expression), 'maybeFalse' => $expression instanceof Node\Expr\FuncCall,
                'unknownInput' => ! $proof['documentedLocals'] && $input instanceof Node\Expr\Variable && ($state['inputs'][$input->name] ?? false),
                'input' => $input instanceof Node\Expr\Variable ? $input->name : null];
        }
        return $this->xml($expression, $state);
    }

    private function root(Node\Expr $expression, array &$proof): ?Node\Expr
    {
        if ($expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name
            && strcasecmp($expression->class->toString(), self::XML) === 0 && self::plainArguments($expression->args)
            && count($expression->args) >= 1 && count($expression->args) <= 2
            && $this->options($expression->args[1]->value ?? null, $proof)) {
            $proof['methods'][] = '__construct';
            return $expression;
        }
        if (! $expression instanceof Node\Expr\FuncCall || ! self::functionName($expression, 'simplexml_load_string')
            || ! self::plainArguments($expression->args) || count($expression->args) < 1 || count($expression->args) > 3) {
            return null;
        }
        $class = $expression->args[1]->value ?? null;
        if ($class !== null && (! $class instanceof Node\Expr\ClassConstFetch || ! $class->class instanceof Node\Name
            || strcasecmp($class->class->toString(), self::XML) !== 0 || ! $class->name instanceof Node\Identifier
            || strtolower($class->name->name) !== 'class')) {
            return null;
        }
        if (! $this->options($expression->args[2]->value ?? null, $proof)) {
            return null;
        }
        $proof['functions'][] = [$expression->name, 'simplexml_load_string'];
        return $expression;
    }

    private function options(?Node\Expr $options, array &$proof): bool
    {
        if ($options === null || $options instanceof Node\Scalar\Int_ && $options->value === 0) {
            return true;
        }
        if ($options instanceof Node\Expr\ConstFetch && $options->name->toString() === 'LIBXML_NONET') {
            $proof['constants'][] = $options->name;
            return true;
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private function xml(Node\Expr $expression, array $state): ?array
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            $value = $state['variables'][$expression->name] ?? null;
        } elseif ($expression instanceof Node\Expr\PropertyFetch && $expression->name instanceof Node\Identifier) {
            $parent = $this->xml($expression->var, $state);
            if ($parent === null || $parent['kind'] === 'list') {
                return null;
            }
            $value = ['kind' => $parent['kind'] === 'node' && ! ($parent['maybeFalse'] ?? false) ? 'proxy' : 'nullable',
                'path' => $parent['path'].'->'.$expression->name->name, 'unknownInput' => $parent['unknownInput'] ?? false,
                'input' => $parent['input'] ?? null];
        } elseif ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->name) === 'xpath' && $this->xml($expression->var, $state) !== null
            && count($expression->args) === 1 && self::plainArguments($expression->args)
            && $expression->args[0]->value instanceof Node\Scalar\String_ && self::elementPath($expression->args[0]->value->value)) {
            $value = ['kind' => 'list', 'path' => '@'.self::key($expression), 'guarded' => false,
                'unknownInput' => $this->xml($expression->var, $state)['unknownInput'] ?? false,
                'input' => $this->xml($expression->var, $state)['input'] ?? null];
        } else {
            return null;
        }
        if ($value !== null && isset($state['present'][$value['path']])) {
            $value['kind'] = 'node';
            $value['count'] = $state['present'][$value['path']];
            $value['maybeFalse'] = false;
        }
        return $value;
    }

    private function inspect(Node $node, array $state, array &$proof, bool $xmlAllowed = false): bool
    {
        if ($node instanceof Node\Expr\Variable && isset($state['variables'][$node->name])) {
            return $xmlAllowed;
        }
        if ($node instanceof Node\Expr\PropertyFetch && $this->xml($node, $state) !== null) {
            $value = $this->xml($node, $state);
            if ($value['kind'] !== 'nullable') {
                $proof['properties'][self::key($node->name)] = $node;
            }
            return $xmlAllowed && $this->inspect($node->var, $state, $proof, true);
        }
        if ($node instanceof Node\Expr\NullsafePropertyFetch && $this->xml($node->var, $state) !== null) {
            return false;
        }
        if ($node instanceof Node\Expr\ArrayDimFetch && $this->xml($node->var, $state) !== null) {
            $value = $this->xml($node->var, $state);
            // Uncast attributes and integer element offsets produce XML aliases,
            // which could later mutate the same document through DOM import.
            if (! $xmlAllowed || ! $node->dim instanceof Node\Scalar\String_ || $value['kind'] === 'list') {
                return false;
            }
            if ($value['kind'] !== 'nullable' && ! ($value['maybeFalse'] ?? false)) {
                $proof['attributes'][self::key($node)] = $node;
            }
            return $this->inspect($node->var, $state, $proof, true);
        }
        if ($node instanceof Node\Expr\Cast\String_ && ($this->xml($node->expr, $state) !== null
            || $node->expr instanceof Node\Expr\ArrayDimFetch && $this->xml($node->expr->var, $state) !== null)) {
            return $this->inspect($node->expr, $state, $proof, true);
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && self::plainArguments($node->args)) {
            $argument = $node->args[0]->value ?? null;
            $value = $argument === null ? null : $this->xml($argument, $state);
            if ($value !== null && count($node->args) === 1
                && (self::functionName($node, 'count') || self::functionName($node, 'is_array') && $value['kind'] === 'list')) {
                $name = strtolower($node->name->getLast());
                if ($name === 'count' && $value['kind'] !== 'list' && ! isset($proof['supportedCounts'][self::key($node)])) {
                    return false;
                }
                $proof['functions'][] = [$node->name, $name];
                if ($name === 'count' && $value['kind'] !== 'list') {
                    $proof['methods'][] = 'count';
                }
                if ($name === 'count' && $value['kind'] !== 'nullable' && $value['kind'] !== 'list' && ! ($value['maybeFalse'] ?? false)) {
                    $proof['arguments'][self::key($argument)] = $node;
                }
                return $this->inspect($argument, $state, $proof, true);
            }
        }
        if ($node instanceof Node\Expr\MethodCall && $this->xml($node->var, $state) !== null) {
            if (! $node->name instanceof Node\Identifier || ! self::plainArguments($node->args)) {
                return false;
            }
            $name = strtolower($node->name->name);
            if ($name === 'getname' && $node->args === [] || $name === 'xpath' && $xmlAllowed && $this->xml($node, $state) !== null) {
                $proof['methods'][] = $name;
                return $this->inspect($node->var, $state, $proof, true);
            }
            return false;
        }
        if ($node instanceof Node\Expr\BinaryOp\Identical || $node instanceof Node\Expr\BinaryOp\NotIdentical) {
            foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$value, $literal]) {
                if ($value instanceof Node\Expr\Variable && $this->xml($value, $state) !== null
                    && $literal instanceof Node\Expr\ConstFetch && strtolower($literal->name->toString()) === 'false') {
                    return true;
                }
                if ($value instanceof Node\Expr\Variable && ($this->xml($value, $state)['kind'] ?? null) === 'list'
                    && $literal instanceof Node\Expr\Array_ && $literal->items === []) {
                    return true;
                }
            }
        }
        if ($node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            if (! $this->inspect($node->left, $state, $proof)) {
                return false;
            }
            $right = $state;
            foreach (self::disjuncts($node->left) as $condition) {
                self::excludeFalse($condition, $right);
                $call = self::countComparison($condition);
                $value = $call === null ? null : $this->xml($call->args[0]->value, $right);
                if ($value !== null && $value['kind'] !== 'list') {
                    $right['present'][$value['path']] = 1;
                }
            }
            return $this->inspect($node->right, $right, $proof);
        }
        if ($node instanceof Node\Expr\Ternary && $node->if === null
            && ($this->xml($node->cond, $state)['kind'] ?? null) === 'list' && $node->else instanceof Node\Expr\Array_) {
            return $this->inspect($node->cond, $state, $proof, true) && $this->inspect($node->else, $state, $proof);
        }
        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec
            || $node instanceof Node\Stmt\Unset_) {
            $target = $node instanceof Node\Stmt\Unset_ ? $node->vars : [$node->var];
            foreach ($target as $write) {
                foreach ((new NodeFinder)->findInstanceOf([$write], Node\Expr\Variable::class) as $variable) {
                    if (isset($state['variables'][$variable->name])) {
                        return false;
                    }
                }
            }
        }
        // Fresh native roots can only enter through the assignment recognizer.
        if ($xmlAllowed && $node instanceof Node\Expr && $this->root($node, $proof) !== null) {
            foreach ($node->args as $argument) {
                if (! $this->inspect($argument->value, $state, $proof)) {
                    return false;
                }
            }
            return true;
        }
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->$name;
            foreach (is_array($child) ? $child : [$child] as $item) {
                if ($item instanceof Node && ! $this->inspect($item, $state, $proof)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<Node\Expr> */
    private static function disjuncts(Node\Expr $node): array
    {
        return $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr
            ? [...self::disjuncts($node->left), ...self::disjuncts($node->right)] : [$node];
    }

    private static function countComparison(Node\Expr $node): ?Node\Expr\FuncCall
    {
        return $node instanceof Node\Expr\BinaryOp\NotIdentical && $node->left instanceof Node\Expr\FuncCall
            && self::functionName($node->left, 'count') && self::plainArguments($node->left->args) && count($node->left->args) === 1
            && $node->right instanceof Node\Scalar\Int_ && $node->right->value === 1 ? $node->left : null;
    }

    private static function functionName(Node\Expr\FuncCall $call, string $name): bool
    {
        return $call->name instanceof Node\Name && strcasecmp($call->name->toString(), $name) === 0;
    }

    private static function nativeFunction(Codebase $codebase, Node\Name $name, string $expected): bool
    {
        if (strcasecmp($name->toString(), $expected) !== 0) {
            return false;
        }
        if (! $name instanceof Node\Name\FullyQualified) {
            $namespaced = $name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), $expected) !== 0
                && $codebase->getFunction($namespaced->toString()) !== null) {
                return false;
            }
        }
        $metadata = $codebase->getFunction($expected);
        if ($metadata === null || ! $metadata->flags->contains(MetadataFlags::BUILTIN)
            || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) || strcasecmp($metadata->identifier->name, $expected) !== 0) {
            return false;
        }
        foreach ($metadata->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return false;
            }
        }
        return true;
    }

    private static function plainArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name !== null || $argument->byRef || $argument->unpack) {
                return false;
            }
        }
        return true;
    }

    private static function elementPath(string $path): bool
    {
        $name = '[A-Za-z_][A-Za-z0-9_.-]*';
        $predicate = '(?:\\[\\s*@'.$name.'\\s*=\\s*(?:"[^"\\r\\n]*"|\'[^\'\\r\\n]*\')\\s*\\])?';
        return preg_match('~\\A/{0,2}'.$name.$predicate.'(?:/{1,2}'.$name.$predicate.')*\\z~D', $path) === 1;
    }

    private static function local(string $name): bool
    {
        return ! in_array($name, ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header', 'argv', 'argc'], true);
    }

    private static function excludeFalse(Node\Expr $condition, array &$state): void
    {
        if (! $condition instanceof Node\Expr\BinaryOp\Identical) {
            return;
        }
        foreach ([[$condition->left, $condition->right], [$condition->right, $condition->left]] as [$value, $literal]) {
            if ($value instanceof Node\Expr\Variable && isset($state['variables'][$value->name])
                && $literal instanceof Node\Expr\ConstFetch && strtolower($literal->name->toString()) === 'false') {
                $state['variables'][$value->name]['maybeFalse'] = false;
            }
        }
    }

    /** Input predicates/opaque assertions can make an otherwise arbitrary XML string literal.
     * Defer cardinality reporting once that original input is constrained. The standard
     * DOCTYPE rejection leaves arbitrary element structure and is the sole query admitted.
     */
    private function restrictInputs(Node $statement, array &$state, array &$proof): void
    {
        // Bodies are inspected as they execute, so later opaque calls cannot erase
        // earlier independently reachable guards. Throwing branches never continue.
        $expression = match (true) {
            $statement instanceof Node\Stmt\If_ => $statement->cond,
            $statement instanceof Node\Stmt\Foreach_ => $statement->expr,
            $statement instanceof Node\Stmt\TryCatch => null,
            default => $statement,
        };
        if ($expression === null) {
            return;
        }
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf([$expression], Node\Expr\CallLike::class) as $call) {
            $parent = $call->getAttribute('parent');
            while ($parent instanceof Node && ! $parent instanceof Node\Stmt) {
                if ($parent instanceof Node\Expr\Throw_) {
                    continue 2;
                }
                $parent = $parent->getAttribute('parent');
            }
            if ($call instanceof Node\Expr && $this->root($call, $proof) !== null) {
                continue;
            }
            if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && self::plainArguments($call->args)) {
                if (self::functionName($call, 'file_get_contents')) {
                    // Stream callbacks may restrict an older caller argument. A
                    // fresh assigned read starts its own unknown input afterwards.
                    foreach ($state['inputs'] as &$unknown) { $unknown = false; }
                    unset($unknown);
                    foreach ($state['variables'] as &$xml) { $xml['unknownInput'] = false; }
                    unset($xml);
                    $proof['functions'][] = [$call->name, 'file_get_contents'];
                    continue;
                }
                foreach (['is_array', 'libxml_use_internal_errors', 'libxml_clear_errors'] as $native) {
                    if (self::functionName($call, $native)) {
                        $proof['functions'][] = [$call->name, $native];
                        continue 2;
                    }
                }
                if (self::functionName($call, 'count') && count($call->args) === 1
                    && ($this->xml($call->args[0]->value, $state) !== null || $call->args[0]->value instanceof Node\Expr\Array_
                        || $call->args[0]->value instanceof Node\Expr\Ternary
                            && ($this->xml($call->args[0]->value->cond, $state)['kind'] ?? null) === 'list')) {
                    continue;
                }
                if (self::functionName($call, 'stripos') && count($call->args) === 2
                    && $call->args[1]->value instanceof Node\Scalar\String_ && strcasecmp($call->args[1]->value->value, '<!DOCTYPE') === 0) {
                    $proof['functions'][] = [$call->name, 'stripos'];
                    continue;
                }
            }
            if ($call instanceof Node\Expr\MethodCall && $this->xml($call->var, $state) !== null && $call->name instanceof Node\Identifier
                && (strtolower($call->name->name) === 'getname' && $call->args === []
                    || strtolower($call->name->name) === 'xpath' && $this->xml($call, $state) !== null)) {
                continue;
            }
            // A custom call can inspect its caller's arguments without an explicit
            // input argument. Its successful return may restrict the XML domain.
            foreach ($state['inputs'] as &$unknown) { $unknown = false; }
            unset($unknown);
            foreach ($state['variables'] as &$xml) { $xml['unknownInput'] = false; }
            unset($xml);
        }
        foreach ($finder->findInstanceOf([$expression], Node\Expr\Variable::class) as $value) {
            if (! ($state['inputs'][$value->name] ?? false)) {
                continue;
            }
            $parent = $value->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $value) {
                continue;
            }
            if ($parent instanceof Node\Expr\BinaryOp\Identical) {
                $other = $parent->left === $value ? $parent->right : $parent->left;
                if ($other instanceof Node\Expr\ConstFetch && in_array(strtolower($other->name->toString()), ['false', 'null'], true)) {
                    continue;
                }
            }
            if ($parent instanceof Node\Arg) {
                $call = $parent->getAttribute('parent');
                if ($call instanceof Node\Expr\CallLike && ($call->getArgs()[0] ?? null) === $parent
                    && $call instanceof Node\Expr && $this->root($call, $proof) !== null) {
                    continue;
                }
                if ($call instanceof Node\Expr\FuncCall && self::functionName($call, 'stripos')
                    && count($call->args) === 2 && self::plainArguments($call->args) && $call->args[0] === $parent
                    && $call->args[1]->value instanceof Node\Scalar\String_ && strcasecmp($call->args[1]->value->value, '<!DOCTYPE') === 0) {
                    $proof['functions'][] = [$call->name, 'stripos'];
                    continue;
                }
            }
            $state['inputs'][$value->name] = false;
            foreach ($state['variables'] as &$xml) {
                if (($xml['input'] ?? null) === $value->name) {
                    $xml['unknownInput'] = false;
                }
            }
            unset($xml);
        }
    }

    private static function expression(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return '$'.$expression->name;
        }
        if ($expression instanceof Node\Expr\PropertyFetch && $expression->name instanceof Node\Identifier) {
            $parent = self::expression($expression->var);
            return $parent === null ? null : $parent.'->'.$expression->name->name;
        }
        return null;
    }
}
