<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\EloquentModelDispatch;
use Ichinya\Laramago\Analyzer\EloquentQueryProvider;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;

/** Prove bounded untyped scope returns preserve their original query object. */
final class ScopeBodyInference
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';

    public function __construct(
        private readonly Codebase $codebase,
        private readonly PhpSource $source,
        private readonly string $modelClass,
        private readonly MacroIndex $macros,
    ) {}

    public function preservesQuery(FunctionLikeMetadata $method): bool
    {
        $statements = (new ModelReflection($this->codebase, $this->source))->methodNode($method)?->stmts ?? [];
        if (count($statements) === 1 && $statements[0] instanceof Node\Stmt\Return_) {
            return $this->preservesExpression($statements[0]->expr, $method);
        }
        // A guarded enum conversion followed by an unconditional fallback.
        $branch = $statements[0] ?? null;
        $fallback = $statements[1] ?? null;
        if (
            count($statements) !== 2
            || ! $branch instanceof Node\Stmt\If_
            || $branch->else !== null
            || $branch->elseifs !== []
            || count($branch->stmts) !== 1
            || ! $branch->stmts[0] instanceof Node\Stmt\Return_
            || ! $fallback instanceof Node\Stmt\Return_
            || ! $branch->cond instanceof Node\Expr\Instanceof_
            || ! $branch->cond->expr instanceof Node\Expr\Variable
            || ! is_string($branch->cond->expr->name)
            || ! $branch->cond->class instanceof Node\Name\FullyQualified
            || ! $this->safeArgument($branch->cond->expr, $method, ltrim($method->parameters[0]->name, '$'))
        ) {
            return false;
        }
        $enum = $this->codebase->getEnum($branch->cond->class->toString());
        if ($enum === null) {
            return false;
        }

        return (
            $this->preservesExpression(
                $branch->stmts[0]->expr,
                $method,
                [$branch->cond->expr->name => $enum->enumType !== null],
            )
            && $this->preservesExpression($fallback->expr, $method)
        );
    }

    /** @param array<string, bool> $guardedEnums Whether a guarded enum parameter is backed. */
    private function preservesExpression(
        ?Node\Expr $expression,
        FunctionLikeMetadata $method,
        array $guardedEnums = [],
    ): bool {
        if ($expression === null) {
            return false;
        }
        [$root, $calls] = PhpSource::chain($expression);
        $query = ltrim($method->parameters[0]->name, '$');
        if (! $root instanceof Node\Expr\Variable || $root->name !== $query || $query === '') {
            return false;
        }
        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                return false;
            }
            $name = strtolower($call->name->toString());
            $forwarded = in_array(
                $name,
                [...EloquentQueryProvider::predicateMethods(), 'orderby', 'orderbydesc'],
                true,
            );
            if (! $forwarded && ! in_array($name, ['where', 'orwhere'], true)) {
                return false;
            }
            if ($forwarded && ! $this->canForward($name)) {
                return false;
            }
            $owner = $forwarded ? self::QUERY : self::BUILDER;
            $native = $this->codebase->getMethod($owner, $name) ?? $this->codebase->getDeclaringMethod(
                $owner,
                $name,
            );
            $return = $native?->returnType?->type;
            $atom = $return?->atomicTypes[0] ?? null;
            if (
                $native === null
                || strcasecmp($native->identifier->class ?? '', $owner) !== 0
                || $return === null
                || count($return->atomicTypes) !== 1
                || ! $atom instanceof NamedObjectType
                || ! $atom->isThis
                || ! in_array(strtolower($atom->name), ['$this', strtolower($owner)], true)
            ) {
                return false;
            }
            foreach ($call->args as $argument) {
                if (
                    ! $argument instanceof Node\Arg
                    || $argument->unpack
                    || $argument->byRef
                    || ! $this->safeArgument($argument->value, $method, $query, $guardedEnums)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function canForward(string $name): bool
    {
        if (
            $this->macros->hasUnknownRegistrations()
            || $this->macros->mayRegister(self::BUILDER, $name)
            || $this->macros->mayRegister(self::QUERY, $name)
        ) {
            return false;
        }
        foreach ([self::BUILDER, $this->modelClass] as $class) {
            foreach ([$name, 'scope'.ucfirst($name)] as $method) {
                if (
                    $this->codebase->getMethod($class, $method) !== null
                    || $this->codebase->getDeclaringMethod($class, $method) !== null
                ) {
                    return false;
                }
            }
            foreach ($this->codebase->getMultipleClasses([
                $class,
                ...$this->codebase->getClassAncestors($class),
            ]) as $metadata) {
                if ($metadata === null) {
                    return false;
                }
                foreach ([...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods] as $method) {
                    if (strcasecmp($method, $name) === 0) {
                        return false;
                    }
                }
            }
        }

        return (new EloquentModelDispatch)->supportsModel($this->codebase, $this->modelClass, $name);
    }

    /** @param array<string, bool> $guardedEnums */
    private function safeArgument(
        Node\Expr $value,
        FunctionLikeMetadata $method,
        string $query,
        array $guardedEnums = [],
    ): bool {
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                if (
                    $item->byRef
                    || $item->unpack
                    || $item->key !== null
                    && ! $this->safeArgument($item->key, $method, $query, $guardedEnums)
                    || ! $this->safeArgument($item->value, $method, $query, $guardedEnums)
                ) {
                    return false;
                }
            }

            return true;
        }

        if (
            $value instanceof Node\Expr\PropertyFetch
            && $value->name instanceof Node\Identifier
            && $value->var instanceof Node\Expr\Variable
            && is_string($value->var->name)
            && array_key_exists($value->var->name, $guardedEnums)
        ) {
            return (
                $value->name->toString() === 'name'
                || $value->name->toString() === 'value'
                && $guardedEnums[$value->var->name]
            );
        }

        // Enum case properties cannot invoke user code or replace the query.
        if (
            $value instanceof Node\Expr\PropertyFetch
            && $value->name instanceof Node\Identifier
            && in_array($value->name->toString(), ['name', 'value'], true)
            && $value->var instanceof Node\Expr\ClassConstFetch
            && $value->var->class instanceof Node\Name\FullyQualified
            && $value->var->name instanceof Node\Identifier
        ) {
            $case = $this->codebase->getEnumCase(
                $value->var->class->toString(),
                $value->var->name->toString(),
            );

            return $case !== null && ($value->name->toString() === 'name' || $case->valueType !== null);
        }

        if ($value instanceof Node\Expr\Variable) {
            if (! is_string($value->name) || $value->name === $query) {
                return false;
            }
            foreach (array_slice($method->parameters, 1) as $parameter) {
                if (ltrim($parameter->name, '$') === $value->name) {
                    return true;
                }
            }

            return false;
        }

        return (
            (
                $value instanceof Node\Scalar\String_
                || $value instanceof Node\Scalar\Int_
                || $value instanceof Node\Scalar\Float_
                || $value instanceof Node\Expr\ConstFetch
            )
            && ! PhpSource::value($value) instanceof UnknownValue
        );
    }
}
