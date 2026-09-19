<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\ParserFactory;
use Throwable;

/** Checks only disjoint literal values at a proven direct class-constructor call. */
final class BladeClassPropArgumentTypes
{
    /**
     * The caller must prove a class tag target, complete parsed attributes, a
     * syntactically bound attribute, and the standard Blade compiler/resolver
     * without a custom component resolver or overridden Component::resolve().
     * Requiring every constructor parameter name proves the direct `new static`
     * branch of the standard resolver. Null means compatible OR unknown; it
     * must never be turned into a diagnostic.
     *
     * @param list<scalar|array|null> $attributeNames Complete post-parse names, including this attribute.
     */
    public function mismatchLiteral(
        BladeClassComponent $component,
        array $attributeNames,
        string $attributeName,
        string $phpExpression,
        bool $bound,
        bool $complete,
        bool $standardResolver,
    ): ?BladePropTypeMismatch {
        if (! $bound || ! $complete || ! $standardResolver || $component->constructor === null) {
            return null;
        }

        $names = [];
        foreach ($attributeNames as $name) {
            if (! is_string($name) || ($camel = self::camel($name)) === null || isset($names[$camel])) {
                return null;
            }
            $names[$camel] = true;
        }
        $parameterName = self::camel($attributeName);
        if ($parameterName === null || ! isset($names[$parameterName])) {
            return null;
        }

        $target = null;
        foreach ($component->constructor as $parameter) {
            if ($parameter['variadic'] || ! isset($names[$parameter['name']])) {
                return null;
            }
            if ($parameter['name'] === $parameterName) {
                $target = $parameter;
            }
        }
        if ($target === null || $target['type'] === null) {
            return null;
        }

        $actual = self::literalKind($phpExpression);
        if ($actual === null || ! self::disjoint($target['type'], $actual)) {
            return null;
        }

        return new BladePropTypeMismatch($parameterName, $target['type'], $actual);
    }

    private static function camel(string $name): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) !== 1) {
            return null;
        }
        $words = preg_split('/[-_]+/', $name);
        if ($words === false || in_array('', $words, true)) {
            return null;
        }

        return lcfirst(implode('', array_map(ucfirst(...), $words)));
    }

    private static function literalKind(string $expression): ?string
    {
        if ($expression === '' || strlen($expression) > 4096) {
            return null;
        }
        $source = '<?php return '.$expression.';';
        try {
            $statements = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
        } catch (Throwable) {
            return null;
        }
        if (
            $statements === null
            || count($statements) !== 1
            || ! $statements[0] instanceof Node\Stmt\Return_
            || $statements[0]->getEndFilePos() !== (strlen($source) - 1)
        ) {
            return null;
        }
        $node = $statements[0]->expr;
        if ($node instanceof Node\Expr\Array_) {
            return 'array';
        }
        if ($node instanceof Node\Scalar\String_) {
            return 'string';
        }
        if ($node instanceof Node\Scalar\LNumber) {
            return 'int';
        }
        if ($node instanceof Node\Scalar\DNumber) {
            return 'float';
        }
        if ($node instanceof Node\Expr\UnaryMinus || $node instanceof Node\Expr\UnaryPlus) {
            return (
                $node->expr instanceof Node\Scalar\LNumber
                    ? 'int'
                    : ($node->expr instanceof Node\Scalar\DNumber ? 'float' : null)
            );
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'null' => 'null',
                'true', 'false' => 'bool',
                default => null,
            };
        }

        return null;
    }

    private static function disjoint(string $declared, string $actual): bool
    {
        $types = str_starts_with($declared, '?')
            ? [substr($declared, 1), 'null']
            : explode('|', $declared);
        foreach ($types as $type) {
            // DNF/intersection syntax and unfamiliar native spellings defer.
            if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $type) !== 1) {
                return false;
            }
            $type = strtolower($type);
            if (
                $type === 'mixed'
                || $type === $actual
                || in_array($type, ['true', 'false'], true)
                && $actual === 'bool'
                || $type === 'iterable'
                && $actual === 'array'
                || $type === 'object'
                && $actual === 'object'
                || $type === 'float'
                && $actual === 'int'
                || $type === 'bool'
                && in_array($actual, ['int', 'float', 'string'], true)
                || in_array($type, ['int', 'float', 'string'], true)
                && in_array($actual, ['int', 'float', 'string', 'bool'], true)
                || $type === 'callable'
            ) {
                return false;
            }
        }

        return true;
    }
}
