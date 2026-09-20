<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Literal expectation extensions in explicitly selected, ordered Pest source files. */
final class PestExpectationCatalog
{
    /** @var list<array{name: string, closure: Node\Expr\Closure|Node\Expr\ArrowFunction, source: string, line: int}>|null */
    private ?array $declarations = null;

    public function __construct(string $root)
    {
        $base = realpath($root);
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $config */
        $config = is_array($options) ? $options['pest-expectations'] ?? null : null;
        /** @var mixed $sources */
        $sources = is_array($config) ? $config['sources'] ?? null : null;
        if ($base === false || ! is_array($sources) || ! array_is_list($sources)) {
            return;
        }

        $base = str_replace('\\', '/', $base).'/';
        $reader = new PhpSource($base);
        $declarations = [];
        /** @var mixed $source */
        foreach ($sources as $source) {
            if (! is_string($source) || ($path = self::selectedFile($base, $source)) === null) {
                return;
            }
            $nodes = $reader->read($path);
            if ($nodes === null || self::shadowsExpect($nodes)) {
                return;
            }
            foreach ($nodes as $statement) {
                // Retain direct declaration candidates without claiming execution.
                // Never descend into tests, callbacks, branches or method bodies.
                if (! $statement instanceof Node\Stmt\Expression) {
                    continue;
                }
                $declaration = self::declaration($statement->expr, $path);
                if ($declaration !== null) {
                    $declarations[] = $declaration;
                }
            }
        }
        $this->declarations = $declarations;
    }

    /**
     * Positive source declarations, in selected file and statement order. An empty
     * list does not prove that Pest has no runtime or plugin extensions.
     *
     * @return list<array{name: string, closure: Node\Expr\Closure|Node\Expr\ArrowFunction, source: string, line: int}>|null
     */
    public function declarations(): ?array
    {
        return $this->declarations;
    }

    /** @param array<array-key, Node> $nodes */
    private static function shadowsExpect(array $nodes): bool
    {
        foreach ($nodes as $node) {
            // Pest's helper is global; namespaced unqualified calls can resolve
            // differently, so this bounded catalog accepts global files only.
            if ($node instanceof Node\Stmt\Namespace_) {
                return true;
            }
            if ($node instanceof Node\Stmt\Function_ && strtolower($node->name->toString()) === 'expect') {
                return true;
            }
            if ($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    if (
                        ($node->type === Node\Stmt\Use_::TYPE_FUNCTION
                        || $use->type === Node\Stmt\Use_::TYPE_FUNCTION)
                        && strtolower($use->getAlias()->toString()) === 'expect'
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return array{name: string, closure: Node\Expr\Closure|Node\Expr\ArrowFunction, source: string, line: int}|null */
    private static function declaration(Node\Expr $expression, string $source): ?array
    {
        if (
            ! $expression instanceof Node\Expr\MethodCall
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'extend'
            || ! $expression->var instanceof Node\Expr\FuncCall
            || ! $expression->var->name instanceof Node\Name
            || strtolower($expression->var->name->toString()) !== 'expect'
            || count($expression->args) !== 2
        ) {
            return null;
        }
        foreach ($expression->var->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef || $argument->name !== null) {
                return null;
            }
        }
        $arguments = [];
        foreach ($expression->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            if (
                $index === 1
                && $argument->name === null
                && $expression->args[0] instanceof Node\Arg
                && $expression->args[0]->name !== null
            ) {
                return null;
            }
            $position = $argument->name === null
                ? $index
                : match ($argument->name->toString()) {
                    'name' => 0,
                    'extend' => 1,
                    default => -1,
                };
            if ($position < 0 || isset($arguments[$position])) {
                return null;
            }
            $arguments[$position] = $argument->value;
        }
        $name = $arguments[0] ?? null;
        $closure = $arguments[1] ?? null;
        if (
            ! $name instanceof Node\Scalar\String_
            || $name->value === ''
            || ! $closure instanceof Node\Expr\Closure
            && ! $closure instanceof Node\Expr\ArrowFunction
        ) {
            return null;
        }

        return [
            'name' => $name->value,
            'closure' => $closure,
            'source' => $source,
            'line' => $expression->getStartLine(),
        ];
    }

    private static function selectedFile(string $base, string $source): ?string
    {
        if (
            $source === ''
            || str_contains($source, "\0")
            || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $source)
            || ! str_ends_with($source, '.php')
        ) {
            return null;
        }
        $path = realpath($base.$source);
        if ($path === false || ! is_file($path)) {
            return null;
        }
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? $path : null;
    }
}
