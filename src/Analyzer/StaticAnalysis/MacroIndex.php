<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use PhpParser\Node;

/** Syntax-only registrations from an explicitly opted-in runtime macro catalog. */
final class MacroIndex
{
    /** @var array<string, array<string, array{EffectiveCallableSignature, Type}|MacroCallable|null>> */
    public array $contracts = [];
    /** @var array<string, true> */
    public array $blocked = [];
    public bool $unknown = false;
    /** @var array<string, array<string, list<string>>> */
    private array $conditions = [];
    /** @var array<string, array<string, true>> */
    private array $possible = [];
    /** @var array<string, true> */
    private array $possibleBlocked = [];
    private bool $possibleUnknown = false;

    public function __construct(string $root)
    {
        $source = new PhpSource($root);
        $json = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $json === false ? null : json_decode($json, true);
        $configured =
            is_array($composer)
            && isset($composer['extra']['laramago'])
            && is_array($composer['extra']['laramago'])
            && array_key_exists('macro-files', $composer['extra']['laramago']);
        if (! $configured) {
            return;
        }
        /** @var mixed $files */
        $files = $composer['extra']['laramago']['macro-files'];
        if (! is_array($files) || ! array_is_list($files)) {
            $this->unknown = true;

            return;
        }
        $paths = array_filter($files, is_string(...));
        if (count($paths) !== count($files)) {
            $this->unknown = true;
        }
        foreach ($paths as $file) {
            // Catalogs stay inside the application and never recursively scan files.
            if (
                ! preg_match('~^(?:app|bootstrap)/[a-zA-Z0-9_./-]+\.php$~', $file)
                || in_array('..', explode('/', $file), true)
            ) {
                $this->unknown = true;
                continue;
            }
            $nodes = $source->read($file);
            if ($nodes === null) {
                $this->unknown = true;
                continue;
            }
            foreach ($nodes as $node) {
                $this->scan($node, true);
            }
        }
    }

    /** @return list<string> */
    public function conditions(string $class, string $method): array
    {
        return $this->conditions[strtolower($class)][$method] ?? [];
    }

    /** @return list<string> */
    public function registrationClasses(): array
    {
        return array_keys($this->contracts + $this->blocked + $this->possible + $this->possibleBlocked);
    }

    public function hasUnknownRegistrations(): bool
    {
        return $this->unknown || $this->possibleUnknown;
    }

    public function mayRegister(string $class, string $method): bool
    {
        $class = strtolower($class);
        if (isset($this->blocked[$class]) || isset($this->possibleBlocked[$class])) {
            return true;
        }
        foreach ([array_keys($this->contracts[$class] ?? []), array_keys($this->possible[$class] ?? [])] as $methods) {
            foreach ($methods as $registered) {
                if (strcasecmp($registered, $method) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $conditions */
    private function scan(Node $node, bool $direct, array $conditions = []): void
    {
        if ($direct && $node instanceof Node\Stmt\If_) {
            $this->scanIf($node, $conditions);

            return;
        }
        if ($node instanceof Node\Expr\StaticCall) {
            $name = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
            if ($name === null || in_array($name, ['macro', 'mixin', 'flushmacros'], true)) {
                if (! $node->class instanceof Node\Name || $node->class->isSpecialClassName()) {
                    $this->unknown = true;
                } else {
                    $class = strtolower($node->class->toString());
                    $key = PhpSource::argument($node->args, 0, 'name');
                    if ($name !== 'macro' || ! $key instanceof Node\Scalar\String_ || $key->value === '') {
                        $this->blocked[$class] = true;
                    } else {
                        $method = $key->value;
                        $closure = PhpSource::argument($node->args, 1, 'macro');
                        $duplicate = array_key_exists($method, $this->contracts[$class] ?? []);
                        $this->contracts[$class][$method] = $direct && ! $duplicate ? $this->contract($closure) : null;
                        $this->conditions[$class][$method] = $conditions;
                    }
                }
            }
        }
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            /** @var mixed $value */
            $value = $properties[$key] ?? null;
            $children = is_array($value) ? $value : [$value];
            foreach (array_filter($children, static fn (mixed $child): bool => $child instanceof Node) as $child) {
                // Only selected top-level expressions are accepted. Provider methods
                // and arbitrary closures are deliberately not assumed to execute.
                $allowed = $direct && ($node instanceof Node\Stmt\Namespace_ || $node instanceof Node\Stmt\Expression);
                $this->scan($child, $allowed, $conditions);
            }
        }
    }

    /** @param list<string> $conditions */
    private function scanIf(Node\Stmt\If_ $if, array $conditions): void
    {
        $condition = $this->condition($if->cond);
        if ($condition === null) {
            $this->scanChildren($if);

            return;
        }
        [$result, $dependencies] = $condition;
        if ($dependencies !== []) {
            $this->recordPossible($if);
        }
        $conditions = array_values(array_unique([...$conditions, ...$dependencies]));
        if ($result) {
            $this->scanStatements($if->stmts, $conditions);

            return;
        }
        foreach ($if->elseifs as $elseif) {
            $condition = $this->condition($elseif->cond);
            if ($condition === null) {
                $this->scanChildren($if);

                return;
            }
            [$result, $dependencies] = $condition;
            if ($dependencies !== []) {
                $this->recordPossible($if);
            }
            $conditions = array_values(array_unique([...$conditions, ...$dependencies]));
            if ($result) {
                $this->scanStatements($elseif->stmts, $conditions);

                return;
            }
        }
        if ($if->else !== null) {
            $this->scanStatements($if->else->stmts, $conditions);
        }
    }

    /**
     * @param array<array-key, Node\Stmt> $statements
     * @param list<string> $conditions
     */
    private function scanStatements(array $statements, array $conditions): void
    {
        foreach ($statements as $statement) {
            $this->scan($statement, true, $conditions);
        }
    }

    private function scanChildren(Node $node): void
    {
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            /** @var mixed $value */
            $value = $properties[$key] ?? null;
            $children = is_array($value) ? $value : [$value];
            foreach (array_filter($children, static fn (mixed $child): bool => $child instanceof Node) as $child) {
                $this->scan($child, false);
            }
        }
    }

    private function recordPossible(Node $node): void
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $name = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
            if ($name === null || in_array($name, ['macro', 'mixin', 'flushmacros'], true)) {
                if (! $node->class instanceof Node\Name || $node->class->isSpecialClassName()) {
                    $this->possibleUnknown = true;
                } else {
                    $class = strtolower($node->class->toString());
                    $key = PhpSource::argument($node->args, 0, 'name');
                    if ($name !== 'macro' || ! $key instanceof Node\Scalar\String_ || $key->value === '') {
                        $this->possibleBlocked[$class] = true;
                    } else {
                        $this->possible[$class][$key->value] = true;
                    }
                }
            }
        }
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            /** @var mixed $value */
            $value = $properties[$key] ?? null;
            $children = is_array($value) ? $value : [$value];
            foreach (array_filter($children, static fn (mixed $child): bool => $child instanceof Node) as $child) {
                $this->recordPossible($child);
            }
        }
    }

    /** @return array{bool, list<string>}|null */
    private function condition(Node\Expr $expression): ?array
    {
        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => [true, []],
                'false' => [false, []],
                default => null,
            };
        }
        if ($expression instanceof Node\Expr\BooleanNot) {
            $condition = $this->condition($expression->expr);

            return $condition === null ? null : [! $condition[0], $condition[1]];
        }
        if (
            $expression instanceof Node\Expr\BinaryOp\BooleanAnd
            || $expression instanceof Node\Expr\BinaryOp\LogicalAnd
            || $expression instanceof Node\Expr\BinaryOp\BooleanOr
            || $expression instanceof Node\Expr\BinaryOp\LogicalOr
        ) {
            $left = $this->condition($expression->left);
            if ($left === null) {
                return null;
            }
            $and =
                $expression instanceof Node\Expr\BinaryOp\BooleanAnd
                || $expression instanceof Node\Expr\BinaryOp\LogicalAnd;
            if ($and && ! $left[0] || ! $and && $left[0]) {
                return $left;
            }
            $right = $this->condition($expression->right);
            if ($right === null) {
                return null;
            }

            return [
                $right[0],
                array_values(array_unique([...$left[1], ...$right[1]])),
            ];
        }
        if (
            ! $expression instanceof Node\Expr\StaticCall
            || ! $expression->class instanceof Node\Name
            || $expression->class->isSpecialClassName()
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'hasmacro'
            || count($expression->args) !== 1
        ) {
            return null;
        }
        $name = PhpSource::argument($expression->args, 0, 'name');
        if (! $name instanceof Node\Scalar\String_ || $name->value === '') {
            return null;
        }
        $class = strtolower($expression->class->toString());
        if (isset($this->blocked[$class])) {
            return null;
        }
        if (! array_key_exists($name->value, $this->contracts[$class] ?? [])) {
            return [false, [$class]];
        }
        if ($this->contracts[$class][$name->value] === null) {
            return null;
        }

        return [
            true,
            array_values(array_unique([$class, ...($this->conditions[$class][$name->value] ?? [])])),
        ];
    }

    /** @return array{EffectiveCallableSignature, Type}|MacroCallable|null */
    private function contract(?Node $node): array|MacroCallable|null
    {
        $callable = self::callable($node);
        if ($callable !== null) {
            return $callable;
        }
        if (! $node instanceof Node\Expr\Closure && ! $node instanceof Node\Expr\ArrowFunction) {
            return null;
        }
        $return = self::type($node->returnType);
        if ($return === null || $node->byRef || $node->static) {
            return null;
        }
        $parameters = [];
        foreach ($node->params as $parameter) {
            $type = self::type($parameter->type);
            if (
                $type === null
                || $parameter->byRef
                || ! $parameter->var instanceof Node\Expr\Variable
                || ! is_string($parameter->var->name)
            ) {
                return null;
            }
            if (
                $parameter->default instanceof Node\Expr\ConstFetch
                && strtolower($parameter->default->name->toString()) === 'null'
            ) {
                $type = Type::union($type, Type::null());
            }
            $parameters[] = new CallableParameter(
                name: '$'.$parameter->var->name,
                type: $type,
                byReference: $parameter->byRef,
                variadic: $parameter->variadic,
                hasDefault: $parameter->default !== null,
            );
        }

        return [new EffectiveCallableSignature($parameters), $return];
    }

    private static function callable(?Node $node): ?MacroCallable
    {
        if (
            $node instanceof Node\Expr\New_
            && $node->class instanceof Node\Name
            && ! $node->class->isSpecialClassName()
        ) {
            return new MacroCallable($node->class->toString(), '__invoke', false);
        }
        if (! $node instanceof Node\Expr\Array_ || count($node->items) !== 2) {
            return null;
        }
        $class = $node->items[0] ?? null;
        $method = $node->items[1] ?? null;
        if (
            ! $class instanceof Node\ArrayItem
            || ! $method instanceof Node\ArrayItem
            || $class->key !== null
            || $method->key !== null
            || $class->unpack
            || $method->unpack
            || $class->byRef
            || $method->byRef
            || ! $class->value instanceof Node\Expr\ClassConstFetch
            || ! $class->value->class instanceof Node\Name
            || $class->value->class->isSpecialClassName()
            || ! $class->value->name instanceof Node\Identifier
            || strtolower($class->value->name->toString()) !== 'class'
            || ! $method->value instanceof Node\Scalar\String_
            || $method->value->value === ''
        ) {
            return null;
        }

        return new MacroCallable($class->value->class->toString(), $method->value->value, true);
    }

    private static function type(?Node $node): ?Type
    {
        if ($node instanceof Node\NullableType) {
            $type = self::type($node->type);

            return $type === null ? null : Type::union($type, Type::null());
        }
        if ($node instanceof Node\UnionType) {
            $result = null;
            foreach ($node->types as $part) {
                $type = self::type($part);
                if ($type === null) {
                    return null;
                }
                $result = $result === null ? $type : Type::union($result, $type);
            }

            return $result;
        }
        if ($node instanceof Node\Name && ! $node->isSpecialClassName()) {
            return Type::namedObject($node->toString());
        }

        return (
            $node instanceof Node\Identifier
                ? match (strtolower($node->toString())) {
                    'int' => Type::int(),
                    'float' => Type::float(),
                    'string' => Type::string(),
                    'bool' => Type::bool(),
                    'null' => Type::null(),
                    'void' => Type::void(),
                    'never' => Type::never(),
                    'mixed' => Type::mixed(),
                    'object' => Type::object(),
                    'array' => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                    default => null,
                } : null
        );
    }
}
