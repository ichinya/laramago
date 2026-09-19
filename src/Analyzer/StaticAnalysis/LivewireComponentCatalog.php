<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Selected, ordered Livewire registrations read as PHP syntax only. */
final class LivewireComponentCatalog
{
    /** @var array<string, array{class: ?string, viewPath: ?string, file: string, line: int}>|null */
    private ?array $components = null;
    private bool $complete = false;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $configuration */
        $configuration = is_array($options) ? $options['livewire-components'] ?? null : null;
        /** @var mixed $files */
        $files = is_array($configuration) ? $configuration['files'] ?? null : null;
        /** @var mixed $version */
        $version = is_array($configuration) ? $configuration['version'] ?? null : null;
        /** @var mixed $complete */
        $complete = is_array($configuration) ? $configuration['complete'] ?? false : null;
        if (
            ! is_array($files)
            || ! array_is_list($files)
            || ! in_array($version, [3, 4], true)
            || ! is_bool($complete)
        ) {
            return;
        }

        $source = new PhpSource($root);
        $components = [];
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
            if (! is_string($file) || ($path = KernelMiddlewareDeclarations::sourcePath($root, $file)) === null) {
                return;
            }
            $nodes = $source->read($path);
            $declarations = $nodes === null ? null : self::declarations($nodes, $path, $provider, $version);
            if ($declarations === null) {
                return;
            }
            // The selected source order asserts activation and effective registration order.
            $components = array_replace($components, $declarations);
        }
        $this->components = $components;
        $this->complete = $complete;
    }

    /** @return array<string, array{class: ?string, viewPath: ?string, file: string, line: int}>|null */
    public function components(): ?array
    {
        return $this->components;
    }

    /** @return array{class: ?string, viewPath: ?string, file: string, line: int}|null */
    public function component(string $name): ?array
    {
        return $this->components[$name] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->components !== null && $this->complete;
    }

    /** Absence is about this asserted registration map, not every Livewire discovery path. */
    public function contains(string $name): ?bool
    {
        if ($this->components === null) {
            return null;
        }
        if (array_key_exists($name, $this->components)) {
            return true;
        }

        return $this->complete ? false : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return array<string, array{class: ?string, viewPath: ?string, file: string, line: int}>|null
     */
    private static function declarations(array $nodes, string $path, ?string $provider, int $version): ?array
    {
        if ($provider !== null) {
            $nodes = self::providerBoot($nodes, $provider);
            if ($nodes === null) {
                return null;
            }
        }
        $components = [];
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
                || strtolower($call->class->toString()) !== 'livewire\\livewire'
                || ! $call->name instanceof Node\Identifier
            ) {
                return null;
            }
            $method = strtolower($call->name->toString());
            $parameters = match ($method) {
                'component' => ['name', 'class'],
                'addcomponent' => $version === 4 ? ['name', 'viewPath', 'class'] : null,
                default => null,
            };
            if ($parameters === null || ($arguments = self::arguments($call->args, $parameters)) === null) {
                return null;
            }
            $name = isset($arguments['name']) ? self::literal($arguments['name']) : null;
            $class = isset($arguments['class']) ? self::className($arguments['class']) : null;
            $viewPath = isset($arguments['viewPath']) ? self::literal($arguments['viewPath']) : null;
            if (
                $name === null
                || $name === ''
                || is_numeric($name)
                || $method === 'component'
                && $class === null
                || $method === 'addcomponent'
                && $class === null
                && $viewPath === null
                || isset($arguments['class'])
                && $class === null
                || isset($arguments['viewPath'])
                && $viewPath === null
            ) {
                return null;
            }
            $components[$name] = [
                'class' => $class,
                'viewPath' => $viewPath,
                'file' => $path,
                'line' => $call->getStartLine(),
            ];
        }

        return $components;
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
        return $expression instanceof Node\Scalar\String_ && $expression->value !== '' ? $expression->value : null;
    }

    private static function className(Node\Expr $expression): ?string
    {
        $value = PhpSource::value($expression);
        if (! is_string($value) || $value === '') {
            return null;
        }
        if ($expression instanceof Node\Expr\ClassConstFetch) {
            if (
                ! $expression->class instanceof Node\Name\FullyQualified
                || strtolower($expression->class->toString()) === 'self'
            ) {
                return null;
            }
        } elseif (! $expression instanceof Node\Scalar\String_) {
            return null;
        }

        return preg_match(
            '~^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$~D',
            $value,
        ) === 1
            ? ltrim($value, '\\')
            : null;
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
