<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Reads a direct UsePolicy declaration; this is not Gate's effective policy resolver. */
final class PolicyAttributeDeclarations
{
    public function __construct(
        private readonly PhpSource $source,
    ) {}

    /** Null means a known class has no direct declaration; UnknownValue means unresolved syntax. */
    public function declaredPolicy(string $file, string $class): string|UnknownValue|null
    {
        $nodes = $this->source->read($file);
        if ($nodes === null) {
            return UnknownValue::Value;
        }
        $matches = self::classes($nodes, ltrim($class, '\\'));
        if (count($matches) !== 1) {
            return UnknownValue::Value;
        }
        $declaration = $matches[0];
        $attributes = [];
        foreach ($declaration->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (
                    strcasecmp($attribute->name->toString(), 'Illuminate\\Database\\Eloquent\\Attributes\\UsePolicy')
                    === 0
                ) {
                    $attributes[] = $attribute;
                }
            }
        }
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) !== 1 || count($attributes[0]->args) !== 1) {
            return UnknownValue::Value;
        }
        $argument = $attributes[0]->args[0];
        if (
            $argument->unpack
            || $argument->byRef
            || $argument->name !== null
            && $argument->name->toString() !== 'class'
        ) {
            return UnknownValue::Value;
        }
        $expression = $argument->value;
        if (
            $expression instanceof Node\Expr\ClassConstFetch
            && ! $expression->class instanceof Node\Name\FullyQualified
        ) {
            if (! $expression->class instanceof Node\Name || strtolower($expression->class->toString()) !== 'self') {
                return UnknownValue::Value;
            }
        }
        $value = PhpSource::value($expression, $declaration->namespacedName?->toString());

        return is_string($value)
        && preg_match(
            '~^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$~',
            $value,
        ) === 1
            ? $value
            : UnknownValue::Value;
    }

    /** @param array<array-key, Node> $nodes
     * @return list<Node\Stmt\Class_>
     */
    private static function classes(array $nodes, string $class): array
    {
        $matches = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                array_push($matches, ...self::classes($node->stmts, $class));
            } elseif (
                $node instanceof Node\Stmt\Class_
                && $node->namespacedName !== null
                && strcasecmp($node->namespacedName->toString(), $class) === 0
            ) {
                $matches[] = $node;
            }
        }

        return $matches;
    }
}
