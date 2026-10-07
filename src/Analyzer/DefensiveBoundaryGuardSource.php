<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node, NodeFinder, NodeTraverser, ParserFactory};
use PhpParser\NodeVisitor\NameResolver;
/** A bounded lexical certificate. It does not infer native values or execute a caller. */
final class DefensiveBoundaryGuardSource
{
    private array $proofs = [];
    private int $visits = 0;
    private bool $serverWritten = false;
    public static function parse(string $contents): array
    {
        if (strlen($contents) > 2000000) {
            throw new \RuntimeException('Selected source exceeds the bound.');
        }
        return (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($contents) ?? []);
    }
    public static function compile(array $nodes): array
    {
        $instance = new self();
        foreach ($nodes as $node) {
            if (in_array('_SERVER', self::writes($node), true)) {
                $instance->serverWritten = true;
            }
        }
        $instance->block($nodes, [], null, null, self::references($nodes));
        return ConfigurationClassificationSource::promote($nodes,$instance->proofs);
    }
    private function block(array $statements, array $environment, ?array $scope, ?array $loop, array $references): void
    {
        foreach ($statements as $statement) {
            if (++$this->visits > 100000) {
                throw new \RuntimeException('Selected source node bound exceeded.');
            }
            if ($statement instanceof Node\Stmt\Namespace_) {
                $this->block($statement->stmts, [], null, null, []);
                continue;
            }
            if ($statement instanceof Node\Stmt\ClassLike) {
                $class = $statement->namespacedName?->toString();
                foreach ($statement->getMethods() as $method) {
                    if ($method->stmts !== null) {
                        $this->callable($method, $class, null);
                    }
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Function_) {
                $this->callable($statement, null, null);
                continue;
            }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign) {
                $assignment = $statement->expr;
                if (self::variable($assignment->var)) {
                    $name = $assignment->var->name;
                    $origin = $this->origin($assignment->expr, $environment, $scope);
                    if (isset($references[$name])) {
                        $origin = null;
                    }
                    if ($origin !== null) {
                        $origin['assignments'][] = self::span($assignment);
                        $environment[$name] = $origin;
                    } else {
                        unset($environment[$name]);
                    }
                } elseif (($name = self::baseVariable($assignment->var)) !== null) {
                    unset($environment[$name]);
                }
                $this->nestedClosures($assignment->expr, $scope);
                continue;
            }
            if ($statement instanceof Node\Stmt\If_) {
                $proof = $this->guard($statement, $environment, $scope, $loop, $references);
                if ($proof !== null) {
                    foreach ($proof['sites'] as $site) {
                        $this->proofs[self::key($site['span'])] = $proof + ['selectedSite' => $site];
                    }
                }
                $this->block($statement->stmts, $environment, $scope, $loop, $references);
                foreach ($statement->elseifs as $elseif) {
                    $this->block($elseif->stmts, $environment, $scope, $loop, $references);
                }
                if ($statement->else !== null) {
                    $this->block($statement->else->stmts, $environment, $scope, $loop, $references);
                }
                foreach (self::writes($statement) as $name) {
                    // A certified empty fallback retains boundary provenance, not a value guarantee.
                    if ($proof !== null && in_array($name, $proof['fallbackVariables'], true)) {
                        continue;
                    }
                    if (($environment[$name]['family'] ?? null) === 'local-empty-array' && self::onlyAppends($statement, $name)) {
                        continue;
                    }
                    unset($environment[$name]);
                }
                if ($statement->else === null && $statement->elseifs === [] && $statement->cond instanceof Node\Expr\BooleanNot && $statement->cond->expr instanceof Node\Expr\Isset_ && count($statement->stmts) === 1 && $statement->stmts[0] instanceof Node\Stmt\Return_ && $statement->stmts[0]->expr instanceof Node\Expr\ConstFetch && strtolower($statement->stmts[0]->expr->name->toString()) === 'false') {
                    foreach ($statement->cond->expr->vars as $member) {
                        if (!$member instanceof Node\Expr\ArrayDimFetch || !self::variable($member->var) || $member->dim === null || !self::pure($member->dim) || ($environment[$member->var->name]['family'] ?? null) !== 'fixed-unpack-integer-offset') {
                            continue;
                        }
                        $environment[$member->var->name]['presenceChecks'][] = ['guard' => self::span($statement), 'dimension' => (new \PhpParser\PrettyPrinter\Standard())->prettyPrintExpr($member->dim), 'indexVariable' => self::variable($member->dim) ? $member->dim->name : null];
                    }
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\Foreach_) {
                $origin = $this->origin($statement->expr, $environment, $scope);
                $inner = $environment;
                foreach ([$statement->keyVar, $statement->valueVar] as $variable) {
                    if (self::variable($variable)) {
                        unset($inner[$variable->name]);
                    }
                }
                if ($origin !== null && !$statement->byRef && self::variable($statement->valueVar)) {
                    $inner[$statement->valueVar->name] = $origin + ['iteration' => []];
                    $inner[$statement->valueVar->name]['iteration'][] = ['role' => 'value', 'span' => self::span($statement), 'byReference' => false];
                    if (self::variable($statement->keyVar)) {
                        $inner[$statement->keyVar->name] = $origin + ['iteration' => []];
                        $inner[$statement->keyVar->name]['iteration'][] = ['role' => 'key', 'span' => self::span($statement), 'byReference' => false];
                    }
                }
                $this->block($statement->stmts, $inner, $scope, ['kind' => 'foreach', 'span' => self::span($statement)], $references);
                foreach (self::writes($statement) as $name) {
                    if (($environment[$name]['family'] ?? null) !== 'local-empty-array' || !self::onlyAppends($statement, $name)) {
                        unset($environment[$name]);
                    }
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\For_) {
                $this->block($statement->stmts, $environment, $scope, ['kind' => 'for', 'span' => self::span($statement)], $references);
                foreach (self::writes($statement) as $name) {
                    if (($environment[$name]['family'] ?? null) !== 'local-empty-array' || !self::onlyAppends($statement, $name)) {
                        unset($environment[$name]);
                    }
                }
                continue;
            }
            if ($statement instanceof Node\Stmt\TryCatch) {
                $this->block($statement->stmts, $environment, $scope, $loop, $references);
                foreach ($statement->catches as $catch) {
                    $this->block($catch->stmts, [], $scope, $loop, $references);
                }
                if ($statement->finally !== null) {
                    $this->block($statement->finally->stmts, [], $scope, $loop, $references);
                }
                foreach (self::writes($statement) as $name) {
                    if (($environment[$name]['family'] ?? null) !== 'local-empty-array' || !self::onlyAppends($statement, $name)) {
                        unset($environment[$name]);
                    }
                }
                continue;
            }
            $this->nestedClosures($statement, $scope);
            // Calls consuming a tracked value require a native by-value parameter certificate.
            $calls = (new NodeFinder())->find($statement, static fn(Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall);
            foreach ($calls as $call) {
                if ($call->isFirstClassCallable()) { continue; }
                foreach ($call->getArgs() as $index => $argument) {
                    $name = self::baseVariable($argument->value);
                    if ($name !== null && isset($environment[$name])) {
                        $environment[$name]['interveningReads'][] = ['call' => self::span($call), 'argumentIndex' => $index, 'argumentName' => $argument->name?->toString(), 'unpacked' => $argument->unpack, 'byReference' => $argument->byRef];
                    }
                }
            }
            foreach (self::writes($statement) as $name) {
                unset($environment[$name]);
            }
        }
    }
    private function callable(Node $node, ?string $class, ?array $outerScope): void
    {
        $scope = $outerScope;
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $scope = ['kind' => $node->getType(), 'class' => $class, 'name' => $node instanceof Node\Stmt\Function_ ? $node->namespacedName->toString() : $node->name->toString(), 'span' => self::span($node), 'startAlternatives' => [$node->getStartFilePos()]];
            foreach ($node->getComments() as $comment) {
                $scope['startAlternatives'][] = $comment->getStartFilePos();
            }
            foreach ($node->attrGroups as $attribute) {
                $scope['startAlternatives'][] = $attribute->getStartFilePos();
            }
        }
        $parameters = [];
        foreach ($node->getParams() as $parameter) {
            if (self::variable($parameter->var) && !$parameter->byRef && !$parameter->variadic) {
                $parameters[$parameter->var->name] = ['family' => 'declared-parameter', 'class' => $parameter->type instanceof Node\Name ? $parameter->type->toString() : null, 'parameter' => self::span($parameter), 'parameterName' => $parameter->var->name, 'roots' => [], 'assignments' => []];
            }
        }
        $nodes = $node instanceof Node\Expr\ArrowFunction ? [$node->expr] : $node->stmts ?? [];
        $references = self::references($nodes);
        $this->block($nodes, $parameters, $scope, null, $references);
    }
    private function nestedClosures(Node $node, ?array $scope): void
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            return;
        }
        foreach ($node->getSubNodeNames() as $field) {
            $value = $node->{$field};
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node\Expr\Closure || $child instanceof Node\Expr\ArrowFunction) {
                    $this->callable($child, null, $scope);
                } elseif ($child instanceof Node) {
                    $this->nestedClosures($child, $scope);
                }
            }
        }
    }
    private function origin(Node\Expr $node, array $environment, ?array $scope): ?array
    {
        if (self::variable($node)) { return $environment[$node->name] ?? null; }
        if ($node instanceof Node\Expr\Array_ && $node->items === []) { return ['family' => 'local-empty-array', 'roots' => [], 'assignments' => []]; }
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            if (!$this->serverWritten && self::serverArgv($node->left) && (self::emptyArray($node->right) || self::null($node->right))) { return ['family' => 'cli-argv', 'roots' => [['kind' => 'server-argv', 'span' => self::span($node)]], 'assignments' => []]; }
            if (self::literalDefault($node->right)) { return $this->origin($node->left, $environment, $scope); }
            return null;
        }
        if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim !== null && self::pure($node->dim)) {
            $origin = $this->origin($node->var, $environment, $scope);
            if ($origin === null || $origin['family'] === 'declared-parameter' || $origin['family'] === 'local-empty-array') { return null; }
            if ($origin['family'] === 'fixed-unpack-integer-offset') {
                $dimension=(new \PhpParser\PrettyPrinter\Standard())->prettyPrintExpr($node->dim); $presence=null;
                foreach ($origin['presenceChecks'] ?? [] as $check) { if ($check['dimension']===$dimension) { $presence=$check; } }
                if ($presence===null) { return null; } $origin['selectedPresence']=$presence;
            }
            $origin['memberReads'][] = self::span($node); return $origin;
        }
        if ($node instanceof Node\Expr\Ternary && $node->if !== null && self::predicate($node->cond, 'is_array') && self::literalDefault($node->else)) {
            $origin = $this->origin($node->if, $environment, $scope); $tested = $this->origin($node->cond->args[0]->value, $environment, $scope);
            if ($origin !== null && $tested !== null && $origin['roots'] === $tested['roots']) { $origin['normalization'][] = self::span($node); return $origin; }
            return null;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && self::ordinary($node->args)) {
            $name = strtolower($node->name->toString());
            if ($name === 'config' && count($node->args) >= 1 && count($node->args) <= 2 && $node->args[0]->value instanceof Node\Scalar\String_
                && (!isset($node->args[1]) || self::literalDefault($node->args[1]->value))) {
                return ['family' => 'configuration', 'roots' => [['kind' => 'config-helper', 'span' => self::span($node), 'key' => $node->args[0]->value->value]], 'assignments' => []];
            }
            if ($name === 'getopt' && count($node->args) === 2 && $node->args[0]->value instanceof Node\Scalar\String_ && $node->args[1]->value instanceof Node\Expr\Array_ && self::pure($node->args[1]->value)) {
                return ['family' => 'cli-getopt', 'roots' => [['kind' => 'getopt', 'span' => self::span($node)]], 'assignments' => []];
            }
            if ($name === 'array_slice' && count($node->args) === 2 && $node->args[1]->value instanceof Node\Scalar\Int_ && $node->args[1]->value->value === 1) {
                $origin = $this->origin($node->args[0]->value, $environment, $scope);
                if ($origin !== null && $origin['family'] === 'cli-argv') { $origin['slices'][] = self::span($node); return $origin; }
            }
            if ($name === 'unpack' && count($node->args) === 2 && $node->args[0]->value instanceof Node\Scalar\String_ && preg_match('/^N[1-9][0-9]?$/D', $node->args[0]->value->value)) {
                return ['family' => 'fixed-unpack-integer-offset', 'roots' => [['kind' => 'unpack', 'span' => self::span($node), 'format' => $node->args[0]->value->value]], 'assignments' => []];
            }
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && self::ordinary($node->args)
            && strcasecmp($node->class->toString(), 'Illuminate\\Support\\Facades\\Config') === 0 && strcasecmp($node->name->toString(), 'get') === 0
            && count($node->args) >= 1 && count($node->args) <= 2 && $node->args[0]->value instanceof Node\Scalar\String_
            && (!isset($node->args[1]) || self::literalDefault($node->args[1]->value))) {
            return ['family' => 'configuration', 'roots' => [['kind' => 'config-facade', 'span' => self::span($node), 'key' => $node->args[0]->value->value]], 'assignments' => []];
        }
        if ($node instanceof Node\Expr\MethodCall && self::variable($node->var) && $node->name instanceof Node\Identifier && strcasecmp($node->name->toString(), 'validate') === 0 && self::ordinary($node->args) && count($node->args) === 1) {
            $receiver = $environment[$node->var->name] ?? null;
            if (($receiver['family'] ?? null) === 'declared-parameter' && $receiver['class'] !== null) {
                return ['family' => 'request-validation', 'roots' => [['kind' => 'request-pseudo', 'span' => self::span($node), 'receiver' => $receiver]], 'assignments' => []];
            }
        }
        return null;
    }
    private function guard(Node\Stmt\If_ $if, array $environment, ?array $scope, ?array $loop, array $references): ?array
    {
        if ($if->else !== null || $if->elseifs !== [] || !self::pure($if->cond)) {
            return null;
        }
        foreach ((new NodeFinder())->findInstanceOf($if->cond, Node\Expr\Variable::class) as $variable) {
            if (!self::variable($variable) || !isset($environment[$variable->name]) || in_array($environment[$variable->name]['family'], ['declared-parameter', 'local-empty-array'], true)) {
                return null;
            }
        }
        $predicates = [];
        foreach ((new NodeFinder())->find($if->cond, static fn(Node $node): bool => $node instanceof Node\Expr\FuncCall && self::primitive($node)) as $call) {
            $origin = $this->origin($call->args[0]->value, $environment, $scope);
            if ($origin === null || in_array($origin['family'], ['declared-parameter', 'local-empty-array'], true)) {
                continue;
            }
            $name = self::baseVariable($call->args[0]->value);
            if ($name !== null && isset($references[$name])) {
                continue;
            }
            $predicates[] = ['name' => strtolower($call->name->toString()), 'span' => self::span($call), 'argument' => self::span($call->args[0]->value), 'origin' => $origin];
        }
        if ($predicates === []) {
            return null;
        }
        $body = self::rejection($if->stmts, $environment, $loop);
        $normalization = self::positiveNormalization($if, $environment);
        $positiveNormalization = $normalization !== null;
        if ($body === null && !$positiveNormalization) {
            return null;
        }
        if ($positiveNormalization && $predicates[0]['origin']['family'] !== 'cli-argv') {
            return null;
        }
        if (($body['kind'] ?? null) === 'return-false') {
            foreach ($predicates as $predicate) {
                if ($predicate['origin']['family'] !== 'fixed-unpack-integer-offset' || empty($predicate['origin']['memberReads']) || ($loop['kind'] ?? null) !== 'for') {
                    return null;
                }
            }
        }
        $sites = [['kind' => 'condition', 'span' => self::span($if->cond)]];
        foreach ($predicates as $predicate) {
            $sites[] = ['kind' => 'predicate', 'span' => $predicate['span'], 'name' => $predicate['name']];
        }
        foreach ((new NodeFinder())->find($if->cond, static fn(Node $node): bool => $node instanceof Node\Expr\BooleanNot || $node instanceof Node\Expr\BinaryOp\Identical) as $node) {
            if ($node instanceof Node\Expr\BooleanNot && $node->expr instanceof Node\Expr\FuncCall && self::primitive($node->expr)) {
                $sites[] = ['kind' => 'negated-predicate', 'span' => self::span($node), 'name' => strtolower($node->expr->name->toString())];
            } elseif ($node instanceof Node\Expr\BinaryOp\Identical && (self::emptyArray($node->left) || self::emptyArray($node->right))) {
                $sites[] = ['kind' => 'empty-comparison', 'span' => self::span($node)];
            }
        }
        $functions = [];
        foreach ((new NodeFinder())->findInstanceOf($if->cond, Node\Expr\FuncCall::class) as $call) {
            $functions[] = ['name' => strtolower($call->name->toString()), 'span' => self::span($call)];
        }
        if ($positiveNormalization) {
            $functions[] = ['name' => 'is_string', 'span' => $normalization['stringPredicate']];
        }
        return ['scope' => $scope, 'guard' => self::span($if), 'condition' => self::span($if->cond), 'sites' => $sites, 'predicates' => $predicates, 'requiredFunctions' => $functions, 'rejection' => $body ?? ['kind' => 'positive-cli-normalization', 'nestedRejection' => $normalization['rejection']], 'fallbackVariables' => $body['fallbackVariables'] ?? [], 'loop' => $loop, 'sourceEffectsCertificate' => 'bounded-lexical-fresh-binding-and-by-value-derivation', 'nativeBindingObserved' => false];
    }
    private static function positiveNormalization(Node\Stmt\If_ $if, array $environment): ?array
    {
        if (!self::predicate($if->cond, 'is_array') || !self::variable($if->cond->args[0]->value) || count($if->stmts) !== 1) {
            return null;
        }
        $loop = $if->stmts[0];
        $raw = $if->cond->args[0]->value->name;
        if (!$loop instanceof Node\Stmt\Foreach_ || $loop->byRef || $loop->keyVar !== null || !self::variable($loop->expr) || $loop->expr->name !== $raw || !self::variable($loop->valueVar) || count($loop->stmts) !== 2) {
            return null;
        }
        $element = $loop->valueVar->name;
        $reject = $loop->stmts[0];
        $append = $loop->stmts[1] instanceof Node\Stmt\Expression ? $loop->stmts[1]->expr : null;
        if (!$reject instanceof Node\Stmt\If_ || $reject->else !== null || $reject->elseifs !== [] || !$reject->cond instanceof Node\Expr\BooleanNot || !self::predicate($reject->cond->expr, 'is_string') || !self::variable($reject->cond->expr->args[0]->value) || $reject->cond->expr->args[0]->value->name !== $element || !$append instanceof Node\Expr\Assign || !$append->var instanceof Node\Expr\ArrayDimFetch || $append->var->dim !== null || !self::variable($append->var->var) || !self::variable($append->expr) || $append->expr->name !== $element || count(array_unique([$raw, $element, $append->var->var->name])) !== 3 || ($environment[$append->var->var->name]['family'] ?? null) !== 'local-empty-array') {
            return null;
        }
        $rejection = self::rejection($reject->stmts, $environment, ['kind' => 'foreach']);
        return $rejection !== null && in_array($rejection['kind'], ['exit', 'throw-builtin-constructor'], true) ? ['stringPredicate' => self::span($reject->cond->expr), 'rejection' => $rejection] : null;
    }
    private static function rejection(array $statements, array $environment, ?array $loop): ?array
    {
        if (count($statements) === 1) {
            $statement = $statements[0];
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Throw_) {
                $value = $statement->expr->expr;
                if ($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name && self::ordinary($value->args) && count($value->args) <= 1 && (!isset($value->args[0]) || self::pure($value->args[0]->value))) {
                    return ['kind' => 'throw-builtin-constructor', 'class' => $value->class->toString(), 'span' => self::span($value)];
                }
                if ($value instanceof Node\Expr\StaticCall && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strcasecmp($value->class->toString(), 'Illuminate\Validation\ValidationException') === 0 && $value->name->toString() === 'withMessages' && self::ordinary($value->args) && count($value->args) === 1 && self::pure($value->args[0]->value)) {
                    return ['kind' => 'throw-validation-exception', 'class' => $value->class->toString(), 'method' => 'withMessages', 'span' => self::span($value)];
                }
            }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Exit_ && ($statement->expr->expr === null || $statement->expr->expr instanceof Node\Scalar\Int_)) {
                return ['kind' => 'exit'];
            }
            if ($statement instanceof Node\Stmt\Return_ && $statement->expr !== null && $statement->expr instanceof Node\Expr\ConstFetch && strtolower($statement->expr->name->toString()) === 'false') {
                return ['kind' => 'return-false'];
            }
        }
        $append = false;
        $fallback = [];
        $continue = false;
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Continue_ && $loop !== null && ($statement->num === null || $statement->num instanceof Node\Scalar\Int_ && $statement->num->value === 1)) {
                $continue = true;
                continue;
            }
            $assignment = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (!$assignment instanceof Node\Expr\Assign) {
                return null;
            }
            if ($assignment->var instanceof Node\Expr\ArrayDimFetch && $assignment->var->dim === null && self::variable($assignment->var->var) && ($environment[$assignment->var->var->name]['family'] ?? null) === 'local-empty-array' && self::pure($assignment->expr)) {
                $append = true;
                continue;
            }
            if (self::variable($assignment->var) && ($environment[$assignment->var->name]['family'] ?? null) === 'configuration' && self::emptyArray($assignment->expr)) {
                $fallback[] = $assignment->var->name;
                continue;
            }
            return null;
        }
        return $append ? ['kind' => $continue ? 'append-diagnostic-and-continue' : 'append-diagnostic', 'fallbackVariables' => $fallback] : null;
    }
    public static function pure(Node $node): bool
    {
        if ($node instanceof Node\Expr\Variable) {
            return is_string($node->name);
        }
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            return true;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true);
        }
        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null || $item->byRef || $item->unpack || !self::pure($item->value) || $item->key !== null && !self::pure($item->key)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            foreach ($node->parts as $part) {
                if (!$part instanceof Node\InterpolatedStringPart && !self::pure($part)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            return self::pure($node->var) && $node->dim !== null && self::pure($node->dim);
        }
        if ($node instanceof Node\Expr\BooleanNot || $node instanceof Node\Expr\Cast\Int_) {
            return self::pure($node->expr);
        }
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalAnd || $node instanceof Node\Expr\BinaryOp\LogicalOr || $node instanceof Node\Expr\BinaryOp\Identical || $node instanceof Node\Expr\BinaryOp\NotIdentical || $node instanceof Node\Expr\BinaryOp\Greater || $node instanceof Node\Expr\BinaryOp\GreaterOrEqual || $node instanceof Node\Expr\BinaryOp\Smaller || $node instanceof Node\Expr\BinaryOp\SmallerOrEqual || $node instanceof Node\Expr\BinaryOp\Coalesce || $node instanceof Node\Expr\BinaryOp\Concat) {
            return self::pure($node->left) && self::pure($node->right);
        }
        if ($node instanceof Node\Expr\Isset_) {
            foreach ($node->vars as $value) {
                if (!$value instanceof Node\Expr\ArrayDimFetch || !self::pure($value)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && self::ordinary($node->args) && in_array(strtolower($node->name->toString()), ['is_array', 'is_string', 'is_int', 'array_is_list', 'count', 'ctype_digit', 'basename'], true)) {
            foreach ($node->args as $argument) {
                if (!self::pure($argument->value)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
    public static function span(Node $node): array
    {
        return [$node->getStartFilePos(), $node->getEndFilePos() + 1];
    }
    public static function key(array $span): string
    {
        return $span[0] . ':' . $span[1];
    }
    private static function predicate(Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strcasecmp($node->name->toString(), $name) === 0 && count($node->args) === 1 && self::ordinary($node->args);
    }
    private static function primitive(Node $node): bool
    {
        foreach (['is_array', 'is_string', 'is_int', 'array_is_list'] as $name) {
            if (self::predicate($node, $name)) {
                return true;
            }
        }
        return false;
    }
    private static function ordinary(array $args): bool
    {
        foreach ($args as $argument) {
            if (!$argument instanceof Node\Arg || $argument->unpack || $argument->byRef || $argument->name !== null) {
                return false;
            }
        }
        return true;
    }
    private static function variable(?Node $node): bool
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name);
    }
    private static function baseVariable(Node $node): ?string
    {
        while ($node instanceof Node\Expr\ArrayDimFetch) {
            $node = $node->var;
        }
        return self::variable($node) ? $node->name : null;
    }
    private static function emptyArray(Node $node): bool
    {
        return $node instanceof Node\Expr\Array_ && $node->items === [];
    }
    private static function null(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString()) === 'null';
    }
    private static function literalDefault(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || self::emptyArray($node) || self::null($node);
    }
    private static function serverArgv(Node $node): bool
    {
        return $node instanceof Node\Expr\ArrayDimFetch && self::variable($node->var) && $node->var->name === '_SERVER' && $node->dim instanceof Node\Scalar\String_ && $node->dim->value === 'argv';
    }
    private static function writes(Node $node): array
    {
        $variables = [];
        foreach ((new NodeFinder())->find($node, static fn(Node $value): bool => $value instanceof Node\Expr\Assign || $value instanceof Node\Expr\AssignRef || $value instanceof Node\Expr\AssignOp || $value instanceof Node\Expr\PreInc || $value instanceof Node\Expr\PostInc || $value instanceof Node\Expr\PreDec || $value instanceof Node\Expr\PostDec) as $write) {
            $name = self::baseVariable($write->var);
            if ($name !== null) {
                $variables[] = $name;
            }
        }
        return array_values(array_unique($variables));
    }
    private static function onlyAppends(Node $node, string $name): bool
    {
        foreach ((new NodeFinder())->find($node, static fn(Node $value): bool => $value instanceof Node\Expr\Assign || $value instanceof Node\Expr\AssignRef || $value instanceof Node\Expr\AssignOp || $value instanceof Node\Expr\PreInc || $value instanceof Node\Expr\PostInc) as $write) {
            if (self::baseVariable($write->var) !== $name) {
                continue;
            }
            if (!$write instanceof Node\Expr\Assign || !$write->var instanceof Node\Expr\ArrayDimFetch || $write->var->dim !== null) {
                return false;
            }
        }
        return true;
    }
    private static function references(array $nodes): array
    {
        $references = [];
        foreach ((new NodeFinder())->find($nodes, static fn(Node $item): bool => $item instanceof Node\Expr\AssignRef || $item instanceof Node\Expr\ClosureUse && $item->byRef || $item instanceof Node\Arg && $item->byRef || $item instanceof Node\Stmt\Global_ || $item instanceof Node\Stmt\Static_ || $item instanceof Node\Stmt\Foreach_ && $item->byRef) as $reference) {
            foreach ((new NodeFinder())->findInstanceOf($reference, Node\Expr\Variable::class) as $variable) {
                if (self::variable($variable)) {
                    $references[$variable->name] = true;
                }
            }
        }
        return $references;
    }
}
