<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Selected effective Blade class-component registrations, parsed without bootstrapping. */
final class BladeComponentAliasCatalog
{
    /** @var array<string, string>|null */
    private ?array $aliases = null;
    private bool $complete = false;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $configuration */
        $configuration = is_array($settings) ? $settings['blade-component-aliases'] ?? null : null;
        /** @var mixed $files */
        $files = is_array($configuration) ? $configuration['files'] ?? null : null;
        /** @var mixed $complete */
        $complete = is_array($configuration) ? $configuration['complete'] ?? false : null;
        if (! is_array($files) || ! array_is_list($files) || ! is_bool($complete)) {
            return;
        }

        $source = new PhpSource($root);
        $aliases = [];
        /** @var mixed $entry */
        foreach ($files as $entry) {
            $provider = null;
            $file = is_string($entry) ? $entry : null;
            if (is_array($entry)) {
                /** @var mixed $selectedFile */
                $selectedFile = $entry['file'] ?? null;
                /** @var mixed $selectedProvider */
                $selectedProvider = $entry['provider'] ?? null;
                if (! is_string($selectedFile) || ! is_string($selectedProvider) || $selectedProvider === '') {
                    return;
                }
                $file = $selectedFile;
                $provider = $selectedProvider;
            }
            if (! is_string($file)) {
                return;
            }
            $path = KernelMiddlewareDeclarations::sourcePath($root, $file);
            if ($path === null) {
                return;
            }
            $nodes = $source->read($path);
            $declarations = $nodes === null ? null : self::declarations($nodes, $provider);
            if ($declarations === null) {
                return;
            }
            // The file list asserts activation and registration order.
            $aliases = array_replace($aliases, $declarations);
        }
        $this->aliases = $aliases;
        $this->complete = $complete;
    }

    /** @return array<string, string>|null Null means unselected or unsupported sources. */
    public function aliases(): ?array
    {
        return $this->aliases;
    }

    public function target(string $alias): ?string
    {
        return $this->aliases[$alias] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->aliases !== null && $this->complete;
    }

    /** Absence is known only for an explicitly complete effective alias registry. */
    public function contains(string $alias): ?bool
    {
        if ($this->aliases === null) {
            return null;
        }
        if (array_key_exists($alias, $this->aliases)) {
            return true;
        }

        return $this->complete ? false : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return array<string, string>|null
     */
    private static function declarations(array $nodes, ?string $provider): ?array
    {
        if ($provider !== null) {
            $nodes = self::providerBoot($nodes, $provider);
            if ($nodes === null) {
                return null;
            }
        }
        $aliases = [];
        foreach ($nodes as $node) {
            if (self::harmless($node)) {
                continue;
            }
            if (! $node instanceof Node\Stmt\Expression || ! $node->expr instanceof Node\Expr\StaticCall) {
                return null;
            }
            $call = $node->expr;
            if (
                ! $call->class instanceof Node\Name\FullyQualified
                || strtolower($call->class->toString()) !== 'illuminate\\support\\facades\\blade'
                || ! $call->name instanceof Node\Identifier
            ) {
                return null;
            }
            $method = strtolower($call->name->toString());
            if ($method === 'component') {
                $arguments = self::arguments($call->args, ['class', 'alias', 'prefix']);
                if ($arguments === null || ! isset($arguments['class'], $arguments['alias'])) {
                    return null;
                }
                $target = self::targetName($arguments['class']);
                $alias = self::literal($arguments['alias']);
                $prefix = isset($arguments['prefix']) ? self::literal($arguments['prefix']) : '';
                if ($target === null || $alias === null || ! self::alias($alias) || $prefix === null) {
                    return null;
                }
                $aliases[self::prefixed($alias, $prefix)] = $target;
            } elseif ($method === 'components') {
                $arguments = self::arguments($call->args, ['components', 'prefix']);
                if ($arguments === null || ! isset($arguments['components'])) {
                    return null;
                }
                $prefix = isset($arguments['prefix']) ? self::literal($arguments['prefix']) : '';
                $array = $arguments['components'];
                if ($prefix === null || ! $array instanceof Node\Expr\Array_) {
                    return null;
                }
                foreach ($array->items as $item) {
                    if (
                        $item->unpack
                        || $item->byRef
                        || ! $item->key instanceof Node\Scalar\String_
                        || ! self::alias($item->key->value)
                    ) {
                        return null;
                    }
                    $target = self::targetName($item->value);
                    // components() swaps its arguments only when the value contains a backslash.
                    if ($target === null || ! str_contains($target, '\\')) {
                        return null;
                    }
                    $aliases[self::prefixed($item->key->value, $prefix)] = $target;
                }
            } else {
                return null;
            }
        }

        return $aliases;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @param list<string> $names
     * @return array<string, Node\Expr>|null
     */
    private static function arguments(array $args, array $names): ?array
    {
        $result = [];
        $named = false;
        foreach ($args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            $name = $argument->name?->toString();
            if ($name !== null) {
                $named = true;
            } elseif ($named) {
                return null;
            } else {
                $name = $names[$index] ?? null;
            }
            if ($name === null || ! in_array($name, $names, true) || isset($result[$name])) {
                return null;
            }
            $result[$name] = $argument->value;
        }

        return $result;
    }

    private static function literal(Node\Expr $expression): ?string
    {
        return $expression instanceof Node\Scalar\String_ ? $expression->value : null;
    }

    private static function targetName(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value === '' ? null : $expression->value;
        }
        if (
            ! $expression instanceof Node\Expr\ClassConstFetch
            || ! $expression->class instanceof Node\Name\FullyQualified
            || ! $expression->name instanceof Node\Identifier
            || strtolower($expression->name->toString()) !== 'class'
        ) {
            return null;
        }
        $value = PhpSource::value($expression);

        return is_string($value)
        && preg_match(
            '~^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$~D',
            $value,
        ) === 1
            ? $value
            : null;
    }

    private static function alias(?string $alias): bool
    {
        return $alias !== null && $alias !== '' && ! is_numeric($alias) && ! str_contains($alias, '\\');
    }

    private static function prefixed(string $alias, string $prefix): string
    {
        return $prefix === '' || $prefix === '0' ? $alias : $prefix.'-'.$alias;
    }

    private static function harmless(Node $node): bool
    {
        return (
            $node instanceof Node\Stmt\Use_
            || $node instanceof Node\Stmt\GroupUse
            || $node instanceof Node\Stmt\Nop
            || $node instanceof Node\Stmt\Declare_
            && $node->stmts === null
            && count($node->declares) === 1
            && $node->declares[0]->key->toString() === 'strict_types'
            && $node->declares[0]->value instanceof Node\Scalar\Int_
            && $node->declares[0]->value->value === 1
        );
    }

    /** @param array<array-key, Node> $nodes
     * @return array<array-key, Node>|null
     */
    private static function providerBoot(array $nodes, string $provider): ?array
    {
        $body = null;
        foreach ($nodes as $node) {
            if (self::harmless($node)) {
                continue;
            }
            if ($node instanceof Node\Stmt\Namespace_) {
                if ($body !== null) {
                    return null;
                }
                $body = self::providerBoot($node->stmts, $provider);
                if ($body === null) {
                    return null;
                }
                continue;
            }
            if (
                ! $node instanceof Node\Stmt\Class_
                || $body !== null
                || $node->namespacedName?->toString() !== ltrim($provider, '\\')
                || $node->extends?->toString() !== 'Illuminate\\Support\\ServiceProvider'
                || $node->isAbstract()
                || $node->attrGroups !== []
            ) {
                return null;
            }
            foreach ($node->stmts as $method) {
                if (
                    ! $method instanceof Node\Stmt\ClassMethod
                    || ! $method->isPublic()
                    || $method->isStatic()
                    || $method->params !== []
                    || $method->attrGroups !== []
                    || $method->stmts === null
                ) {
                    return null;
                }
                $name = strtolower($method->name->toString());
                if ($name === 'register' && $method->stmts === []) {
                    continue;
                }
                if ($name !== 'boot' || $body !== null) {
                    return null;
                }
                $body = $method->stmts;
            }
        }

        return $body;
    }
}
