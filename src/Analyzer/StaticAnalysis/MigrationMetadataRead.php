<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Narrow native metadata operations which do not alter table columns. */
final class MigrationMetadataRead
{
    public static function schema(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name
            && in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
            && $call->name instanceof Node\Identifier
            && in_array(strtolower($call->name->toString()), [
                'hascolumn', 'hascolumns', 'hastable', 'getcolumns', 'getcolumnlisting', 'getcolumntype',
                'getindexes', 'hasindex', 'getforeignkeys', 'gettables', 'gettablelisting', 'getviews',
            ], true);
    }

    public static function foreignKeys(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name
            && in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
            && $call->name instanceof Node\Identifier
            && in_array(strtolower($call->name->toString()), ['enableforeignkeyconstraints', 'disableforeignkeyconstraints'], true)
            && $call->args === [];
    }

    public static function connection(Node\Expr\CallLike $call): bool
    {
        if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier || $call->args !== []
            || ! in_array(strtolower($call->name->toString()), ['getdrivername', 'transactionlevel'], true)) {
            return false;
        }
        $root = $call->var;

        return $root instanceof Node\Expr\StaticCall && self::database($root)
            && $root->name instanceof Node\Identifier && strcasecmp($root->name->toString(), 'connection') === 0
            && $root->args === [];
    }

    public static function database(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name
            && in_array(strtolower($call->class->toString()), ['db', 'illuminate\\support\\facades\\db'], true);
    }

    public static function collection(Node\Expr\CallLike $call): bool
    {
        if ($call instanceof Node\Expr\MethodCall) {
            return $call->name instanceof Node\Identifier && strcasecmp($call->name->toString(), 'firstWhere') === 0
                && count($call->args) === 2 && self::literalArguments($call)
                && $call->var instanceof Node\Expr\FuncCall && self::collection($call->var);
        }
        if (! $call instanceof Node\Expr\FuncCall || ! $call->name instanceof Node\Name
            || strtolower($call->name->toString()) !== 'collect' || count($call->args) !== 1) {
            return false;
        }
        $namespaced = $call->name->getAttribute('namespacedName');
        $argument = $call->args[0];

        return (! $namespaced instanceof Node\Name || strtolower($namespaced->toString()) === 'collect')
            && $argument instanceof Node\Arg && ! $argument->unpack && ! $argument->byRef
            && $argument->value instanceof Node\Expr\StaticCall && self::schema($argument->value);
    }

    /** String and logging helpers cannot emit DDL regardless of arguments. */
    public static function neutralStatic(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name
            && in_array(strtolower($call->class->toString()), [
                'log', 'str', 'illuminate\\support\\facades\\log', 'illuminate\\support\\str',
            ], true);
    }

    /** Literal read-only SQL executors never alter columns. */
    public static function readOnlySql(Node\Expr\StaticCall $call): bool
    {
        if (! $call->name instanceof Node\Identifier
            || ! in_array(strtolower($call->name->toString()), ['scalar', 'selectscalar', 'selectone', 'select'], true)) {
            return false;
        }
        $sql = PhpSource::value(PhpSource::argument($call->args, 0, 'statement'));

        return is_string($sql)
            && preg_match('/^\s*(?:select|with|show|explain|describe|values)\b|^\s*pragma\s+[a-z_]+\s*$/i', $sql) === 1;
    }

    /** Container resolution emits no DDL itself; constructor and method bodies are scanned by the caller. */
    public static function container(Node\Expr\FuncCall $call): bool
    {
        return $call->name instanceof Node\Name
            && in_array(strtolower($call->name->getLast()), ['app', 'resolve'], true);
    }

    /** Exception guards abort a migration path; constructing a built-in literal exception emits no DDL.
     * @param array<array-key, Node> $nodes
     * @return array<int, true>
     */
    public static function guardExceptions(array $nodes): array
    {
        $safe = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\If_::class) as $guard) {
            if (count($guard->stmts) !== 1 || ! $guard->stmts[0] instanceof Node\Stmt\Expression
                || ! $guard->stmts[0]->expr instanceof Node\Expr\Throw_) {
                continue;
            }
            $exception = $guard->stmts[0]->expr->expr;
            if ($exception instanceof Node\Expr\New_ && $exception->class instanceof Node\Name
                && in_array(strtolower($exception->class->toString()), ['runtimeexception', 'logicexception', 'invalidargumentexception', 'unexpectedvalueexception'], true)
                && self::literalArguments($exception)) {
                $safe[spl_object_id($exception)] = true;
            }
        }

        return $safe;
    }

    private static function literalArguments(Node\Expr\CallLike $call): bool
    {
        foreach ($call->getArgs() as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return false;
            }
            $value = PhpSource::value($argument->value);
            if ($value === UnknownValue::Value || is_array($value)) {
                return false;
            }
        }

        return true;
    }
}
