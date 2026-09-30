<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedIntegerCalls;
use Mago\Sdk\Analyzer\Assertion\TypeAssertion;
use Mago\Sdk\Analyzer\Assertion\TypeAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\FunctionAssertionProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationAssertions;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\IntegerTypeKind;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use PhpParser\Node;

/** Successful integer checks retain literal bounds from a fresh filter_var assignment. */
final class ValidatedIntegerAssertionProvider implements FunctionAssertionProvider
{
    public function __construct(public readonly ValidatedIntegerCalls $calls = new ValidatedIntegerCalls) {}

    public function getTargets(): array
    {
        return [FunctionTarget::exact('is_int')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $invocation = $context->invocation;
        $candidate = $this->calls->call($invocation->span);
        $argument = $invocation->getArgument(0, 'value');
        if ($invocation->kind !== InvocationKind::Function || strcasecmp($invocation->name, 'is_int') !== 0
            || count($invocation->arguments) !== 1 || $argument === null || $argument->unpacked || $argument->placeholder
            || $argument->name !== null || $candidate === null) {
            return null;
        }
        ['guard' => $guard, 'filter' => $filter] = $candidate;
        if ($argument->span->start !== $guard->args[0]->getStartFilePos()
            || $argument->span->end !== $guard->args[0]->getEndFilePos() + 1
            || $argument->expression !== '$'.$guard->args[0]->value->name
            || ! self::nativeName($context, $guard->name, 'is_int')
            || ! self::nativeName($context, $filter->name, 'filter_var')
            || ! $filter->args[1]->value instanceof Node\Expr\ConstFetch
            || ! self::nativeName($context, $filter->args[1]->value->name, 'FILTER_VALIDATE_INT', true)) {
            return null;
        }
        $outer = self::literalMap($filter->args[2]->value);
        $options = $outer === null ? null : self::literalMap($outer['options'] ?? null);
        if ($outer === null || $options === null || array_diff(array_keys($outer), ['options', 'flags']) !== []
            || $options === [] || array_diff(array_keys($options), ['min_range', 'max_range']) !== []) {
            return null;
        }
        if (isset($outer['flags'])) {
            $flags = $outer['flags'];
            if (self::integer($flags) !== 0 && (! $flags instanceof Node\Expr\ConstFetch
                || ! self::nativeName($context, $flags->name, 'FILTER_NULL_ON_FAILURE', true))) {
                return null;
            }
        }
        $minimum = isset($options['min_range']) ? self::integer($options['min_range']) : null;
        $maximum = isset($options['max_range']) ? self::integer($options['max_range']) : null;
        if (isset($options['min_range']) && $minimum === null || isset($options['max_range']) && $maximum === null
            || $minimum !== null && $maximum !== null && $minimum > $maximum) {
            return null;
        }
        $kind = $minimum === null ? IntegerTypeKind::To : ($maximum === null ? IntegerTypeKind::From : IntegerTypeKind::Range);
        $type = Type::fromAtomic(new ScalarType(ScalarTypeKind::Integer, new IntegerType($kind, $minimum, $maximum)));
        return new InvocationAssertions(ifTrueAssertions: ['$value' => [new TypeAssertion(TypeAssertionKind::IsType, $type)]]);
    }

    private static function nativeName(AssertionProviderContext $context, Node\Name $name, string $expected, bool $constant = false): bool
    {
        if (($constant ? $name->toString() !== $expected : strcasecmp($name->toString(), $expected) !== 0)) {
            return false;
        }
        if (! $name instanceof Node\Name\FullyQualified) {
            $namespaced = $name->getAttribute('namespacedName');
            if ($namespaced instanceof Node\Name && $namespaced->toString() !== $expected) {
                $shadow = $constant ? $context->codebase->getConstant($namespaced->toString()) : $context->codebase->getFunction($namespaced->toString());
                if ($shadow !== null) {
                    return false;
                }
            }
        }
        $metadata = $constant ? $context->codebase->getConstant($expected) : $context->codebase->getFunction($expected);
        return $metadata !== null && $metadata->flags->contains(MetadataFlags::BUILTIN);
    }

    /** @return array<string, Node\Expr>|null */
    private static function literalMap(?Node\Expr $node): ?array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return null;
        }
        $values = [];
        foreach ($node->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_
                || array_key_exists($item->key->value, $values)) {
                return null;
            }
            $values[$item->key->value] = $item->value;
        }
        return $values;
    }

    private static function integer(Node\Expr $node): ?int
    {
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if (($node instanceof Node\Expr\UnaryMinus || $node instanceof Node\Expr\UnaryPlus) && $node->expr instanceof Node\Scalar\Int_) {
            $value = $node instanceof Node\Expr\UnaryMinus ? -$node->expr->value : $node->expr->value;
            return is_int($value) ? $value : null;
        }
        return null;
    }
}
