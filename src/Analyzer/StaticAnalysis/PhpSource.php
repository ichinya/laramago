<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** Reads syntax only. Files, expressions and application classes are never executed. */
final class PhpSource
{
    private readonly Parser $parser;
    /** @var array<string, array<array-key, Node>|null> */
    private array $files = [];
    /** @var array<string, string> */
    public array $warnings = [];

    public function __construct(
        public readonly string $root,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function path(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('~^(?:[A-Za-z]:[/\\\\]|\\\\\\\\)~', $path)
            ? $path
            : $this->root.'/'.$path;
    }

    /** @return array<array-key, Node>|null */
    public function read(string $path): ?array
    {
        $path = $this->path($path);
        if (array_key_exists($path, $this->files)) {
            return $this->files[$path];
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->warnings[$path] = 'Cannot read PHP source for static model metadata.';

            return $this->files[$path] = null;
        }
        try {
            $nodes = $this->parser->parse($contents) ?? [];

            return $this->files[$path] = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (Error $error) {
            $this->warnings[$path] = 'Cannot parse PHP source for static model metadata: '.$error->getMessage();

            return $this->files[$path] = null;
        }
    }

    /**
     * Only literal syntax is accepted, excluding function and constant evaluation.
     * Nested array values are unnecessary for casts and column names and stay unknown.
     *
     * @return scalar|array<array-key, scalar|null|UnknownValue>|null|UnknownValue
     */
    public static function value(
        ?Node $node,
        ?string $class = null,
        ?string $staticClass = null,
    ): string|int|float|bool|array|UnknownValue|null {
        if (
            $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
        ) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => UnknownValue::Value,
            };
        }
        if (
            $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'class'
        ) {
            $name = $node->class->toString();

            return match (strtolower($name)) {
                'self' => $class ?? UnknownValue::Value,
                'static' => $staticClass ?? $class ?? UnknownValue::Value,
                default => $name,
            };
        }
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item->unpack || $item->byRef) {
                    return UnknownValue::Value;
                }
                $value = self::value($item->value, $class, $staticClass);
                if (is_array($value)) {
                    $value = UnknownValue::Value;
                }
                if ($item->key === null) {
                    $result[] = $value;
                } else {
                    $key = self::value($item->key, $class, $staticClass);
                    if (! is_string($key) && ! is_int($key)) {
                        return UnknownValue::Value;
                    }
                    $result[$key] = $value;
                }
            }

            return $result;
        }

        return UnknownValue::Value;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args */
    public static function argument(array $args, int $position, string $name): ?Node\Expr
    {
        foreach ($args as $index => $arg) {
            if (
                $arg instanceof Node\Arg
                && ($arg->name?->toString() === $name
                || $arg->name === null
                && $index === $position)
            ) {
                return $arg->unpack ? null : $arg->value;
            }
        }

        return null;
    }

    /** @return array{Node\Expr, list<Node\Expr\MethodCall>} */
    public static function chain(Node\Expr $expression): array
    {
        $calls = [];
        while ($expression instanceof Node\Expr\MethodCall) {
            $calls[] = $expression;
            $expression = $expression->var;
        }

        return [$expression, array_reverse($calls)];
    }
}
