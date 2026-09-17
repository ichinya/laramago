<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Tracks fresh literal locals and recognizes scalar preparation without executing PHP. */
final class SchemaPreparation
{
    /** @var array<string, true> */
    private array $seen = [];
    /** @var array<string, true> */
    private array $scalars = [];
    /** @var array<string, scalar|list<scalar|null>|null> */
    private array $literals = [];

    public function __construct(Node\Expr\Closure $callback)
    {
        $this->seen = self::variables([...$callback->params, ...$callback->uses]);
        foreach ([
            'this',
            'GLOBALS',
            '_SERVER',
            '_GET',
            '_POST',
            '_FILES',
            '_COOKIE',
            '_SESSION',
            '_REQUEST',
            '_ENV',
        ] as $name) {
            $this->seen[$name] = true;
        }
    }

    public function accepts(Node\Stmt $statement): bool
    {
        $assignment = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
        $name =
            $assignment instanceof Node\Expr\Assign && $assignment->var instanceof Node\Expr\Variable
                ? $assignment->var->name
                : null;
        $value = $assignment instanceof Node\Expr\Assign ? $this->value($assignment->expr) : UnknownValue::Value;
        $accepted =
            is_string($name)
            && ! isset($this->seen[$name])
            && $assignment instanceof Node\Expr\Assign
            && ($this->scalar($assignment->expr) || is_array($value));
        // Previously mentioned variables may alias the Blueprint or captured state.
        // Never overwrite them, and forget scalar knowledge after other statements
        // mention a local (including references hidden inside column arguments).
        $mentioned = self::variables([$statement]);
        $this->seen += $mentioned;
        if ($accepted) {
            if (! is_array($value)) {
                $this->scalars[$name] = true;
            }
            if ($value !== UnknownValue::Value) {
                $this->literals[$name] = $value;
            }

            return true;
        }
        $this->scalars = array_diff_key($this->scalars, $mentioned);
        $this->literals = array_diff_key($this->literals, $mentioned);

        return $statement instanceof Node\Stmt\Nop;
    }

    /**
     * Only fresh by-value targets over finite literal lists can be expanded.
     * The caller expires all mentioned locals after the loop; iteration bindings
     * never escape, and each body still passes the usual mutation guards.
     *
     * @return list<self>|null
     */
    public function iterations(Node\Stmt\Foreach_ $loop): ?array
    {
        $name = $loop->valueVar instanceof Node\Expr\Variable ? $loop->valueVar->name : null;
        $values = $this->value($loop->expr);
        if (
            $loop->byRef
            || $loop->keyVar !== null
            || ! is_string($name)
            || isset($this->seen[$name])
            || ! is_array($values)
            || count($values) > 64
            || count($loop->stmts) > 64
            || (count($values) * count($loop->stmts)) > 256
        ) {
            return null;
        }
        $iterations = [];
        foreach ($values as $value) {
            $iteration = clone $this;
            $iteration->seen[$name] = true;
            $iteration->scalars[$name] = true;
            $iteration->literals[$name] = $value;
            $iterations[] = $iteration;
        }

        return $iterations;
    }

    /**
     * Reads syntax and previously proven locals only; never folds expressions.
     *
     * @return scalar|list<scalar|null>|null|UnknownValue
     */
    public function value(?Node $expression): string|int|float|bool|array|UnknownValue|null
    {
        if ($expression instanceof Node\Expr\Variable) {
            return is_string($expression->name) && array_key_exists($expression->name, $this->literals)
                ? $this->literals[$expression->name]
                : UnknownValue::Value;
        }
        if ($expression instanceof Node\Expr\Array_) {
            $values = [];
            foreach ($expression->items as $item) {
                if ($item->key !== null || $item->unpack || $item->byRef) {
                    return UnknownValue::Value;
                }
                $value = $this->value($item->value);
                if (is_array($value) || $value === UnknownValue::Value) {
                    return UnknownValue::Value;
                }
                $values[] = $value;
            }

            return $values;
        }
        if (
            $expression instanceof Node\Scalar\String_
            || $expression instanceof Node\Scalar\Int_
            || $expression instanceof Node\Scalar\Float_
        ) {
            return $expression->value;
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => UnknownValue::Value,
            };
        }

        return UnknownValue::Value;
    }

    /** @param list<Node\Expr\MethodCall> $chain */
    public function safeArguments(array $chain): bool
    {
        foreach ($chain as $call) {
            foreach ($call->args as $argument) {
                if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                    return false;
                }
                if (
                    (new NodeFinder)->findFirst(
                        [$argument->value],
                        fn (Node $node): bool => $node instanceof Node\Expr\Assign
                        || $node instanceof Node\Expr\AssignRef
                        || $node instanceof Node\Expr\AssignOp
                        || $node instanceof Node\Expr\CallLike
                        && ! $this->safeCall($node)
                        || $node instanceof Node\Expr\Closure
                        || $node instanceof Node\Expr\ArrowFunction
                        || $node instanceof Node\Expr\Include_
                        || $node instanceof Node\Expr\Eval_
                        || $node instanceof Node\Expr\Exit_
                        || $node instanceof Node\Expr\Yield_
                        || $node instanceof Node\Expr\YieldFrom
                        || $node instanceof Node\Expr\PreInc
                        || $node instanceof Node\Expr\PreDec
                        || $node instanceof Node\Expr\PostInc
                        || $node instanceof Node\Expr\PostDec
                        || $node instanceof Node\Expr\PropertyFetch
                        || $node instanceof Node\Expr\NullsafePropertyFetch
                        || $node instanceof Node\Expr\StaticPropertyFetch
                        || $node instanceof Node\Expr\ArrayDimFetch
                        || $node instanceof Node\Expr\Cast
                        || $node instanceof Node\Expr\Clone_
                        || $node instanceof Node\Expr\ShellExec
                        || $node instanceof Node\Expr\Throw_,
                    ) !== null
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function safeCall(Node\Expr\CallLike $call): bool
    {
        if (
            ! $call instanceof Node\Expr\StaticCall
            || ! $call->class instanceof Node\Name
            || strcasecmp($call->class->toString(), 'Illuminate\\Support\\Facades\\DB') !== 0
            || ! $call->name instanceof Node\Identifier
        ) {
            return false;
        }
        if (strcasecmp($call->name->toString(), 'getDriverName') === 0) {
            return $call->args === [];
        }

        // DB::raw only wraps an expression. Never execute or interpret its SQL.
        return (
            strcasecmp($call->name->toString(), 'raw') === 0
            && count($call->args) === 1
            && $call->args[0] instanceof Node\Arg
            && ! $call->args[0]->unpack
            && ! $call->args[0]->byRef
            && ($call->args[0]->name === null || $call->args[0]->name->toString() === 'value')
            && is_string($this->value($call->args[0]->value))
        );
    }

    /**
     * @param array<array-key, Node> $nodes
     * @return array<string, true>
     */
    private static function variables(array $nodes): array
    {
        $names = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\Variable::class) as $variable) {
            if (! is_string($variable->name)) {
                continue;
            }
            $names[$variable->name] = true;
        }

        return $names;
    }

    private function scalar(Node\Expr $expression): bool
    {
        if (
            $expression instanceof Node\Scalar\String_
            || $expression instanceof Node\Scalar\Int_
            || $expression instanceof Node\Scalar\Float_
        ) {
            return true;
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($expression->name->toString()), ['true', 'false', 'null'], true);
        }
        if ($expression instanceof Node\Expr\Variable) {
            return is_string($expression->name) && isset($this->scalars[$expression->name]);
        }
        if ($expression instanceof Node\Expr\BinaryOp) {
            return $this->scalar($expression->left) && $this->scalar($expression->right);
        }
        if (
            $expression instanceof Node\Expr\BooleanNot
            || $expression instanceof Node\Expr\UnaryMinus
            || $expression instanceof Node\Expr\UnaryPlus
        ) {
            return $this->scalar($expression->expr);
        }
        if ($expression instanceof Node\Expr\Ternary) {
            return (
                $this->scalar($expression->cond)
                && ($expression->if === null || $this->scalar($expression->if))
                && $this->scalar($expression->else)
            );
        }
        if ($expression instanceof Node\Scalar\InterpolatedString) {
            foreach ($expression->parts as $part) {
                if (! $part instanceof Node\InterpolatedStringPart && ! $this->scalar($part)) {
                    return false;
                }
            }

            return true;
        }

        // Laravel reads the configured driver name here. Recognize the operation,
        // but never invoke the facade, resolve a connection, or choose a SQL branch.
        return (
            $expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
            && strcasecmp($expression->class->toString(), 'Illuminate\\Support\\Facades\\DB') === 0
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), 'getDriverName') === 0
            && $expression->args === []
        );
    }
}
