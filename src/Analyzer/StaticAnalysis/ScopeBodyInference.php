<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;

/** Prove a single untyped scope return preserves its original query object. */
final class ScopeBodyInference
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    public function __construct(
        private readonly Codebase $codebase,
        private readonly PhpSource $source,
    ) {}

    public function preservesQuery(FunctionLikeMetadata $method): bool
    {
        $expression = (new ModelReflection($this->codebase, $this->source))->returnExpression($method);
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
            if (! in_array($name, ['where', 'orwhere'], true)) {
                return false;
            }
            $native = $this->codebase->getMethod(self::BUILDER, $name) ?? $this->codebase->getDeclaringMethod(
                self::BUILDER,
                $name,
            );
            $return = $native?->returnType?->type;
            $atom = $return?->atomicTypes[0] ?? null;
            if (
                $native === null
                || strcasecmp($native->identifier->class ?? '', self::BUILDER) !== 0
                || $return === null
                || count($return->atomicTypes) !== 1
                || ! $atom instanceof NamedObjectType
                || ! $atom->isThis
                || ! in_array(strtolower($atom->name), ['$this', strtolower(self::BUILDER)], true)
            ) {
                return false;
            }
            foreach ($call->args as $argument) {
                if (
                    ! $argument instanceof Node\Arg
                    || $argument->unpack
                    || $argument->byRef
                    || ! $this->safeArgument($argument->value, $method, $query)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function safeArgument(Node\Expr $value, FunctionLikeMetadata $method, string $query): bool
    {
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
