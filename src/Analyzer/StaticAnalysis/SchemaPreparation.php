<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Recognizes scalar preparation inside a Blueprint callback without evaluating it. */
final class SchemaPreparation
{
    /** @var array<string, true> */
    private array $seen = [];
    /** @var array<string, true> */
    private array $scalars = [];

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
        $accepted =
            is_string($name)
            && ! isset($this->seen[$name])
            && $assignment instanceof Node\Expr\Assign
            && $this->scalar($assignment->expr);
        // Previously mentioned variables may alias the Blueprint or captured state.
        // Never overwrite them, and forget scalar knowledge after other statements
        // mention a local (including references hidden inside column arguments).
        $mentioned = self::variables([$statement]);
        $this->seen += $mentioned;
        if ($accepted) {
            $this->scalars[$name] = true;

            return true;
        }
        $this->scalars = array_diff_key($this->scalars, $mentioned);

        return $statement instanceof Node\Stmt\Nop;
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
