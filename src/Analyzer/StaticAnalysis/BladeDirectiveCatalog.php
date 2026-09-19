<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Selected, ordered Blade registrations read as PHP syntax without running application code. */
final class BladeDirectiveCatalog
{
    /** @var array<array-key, BladeDirectiveDeclaration>|null */
    private ?array $directives = null;
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
        $configuration = is_array($settings) ? $settings['blade-directives'] ?? null : null;
        /** @var mixed $files */
        $files = is_array($configuration) ? $configuration['files'] ?? null : null;
        /** @var mixed $complete */
        $complete = is_array($configuration) ? $configuration['complete'] ?? false : null;
        if (! is_array($files) || ! array_is_list($files) || ! is_bool($complete)) {
            return;
        }

        $source = new PhpSource($root);
        $directives = [];
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
            $declarations = $nodes === null ? null : self::declarations($nodes, $provider, $path);
            if ($declarations === null) {
                return;
            }
            // Selection asserts activation and this order; PHP alone cannot prove either.
            $directives = array_replace($directives, $declarations);
        }
        $this->directives = $directives;
        $this->complete = $complete;
    }

    /** @return array<array-key, BladeDirectiveDeclaration>|null Null means unselected or unsupported sources. */
    public function directives(): ?array
    {
        return $this->directives;
    }

    public function get(string $name): ?BladeDirectiveDeclaration
    {
        return $this->directives[$name] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->directives !== null && $this->complete;
    }

    /** Absence is known only when the effective custom-directive registry is asserted complete. */
    public function contains(string $name): ?bool
    {
        if ($this->directives === null) {
            return null;
        }
        if (array_key_exists($name, $this->directives)) {
            return true;
        }

        return $this->complete ? false : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return array<array-key, BladeDirectiveDeclaration>|null
     */
    private static function declarations(array $nodes, ?string $provider, string $path): ?array
    {
        if ($provider !== null) {
            $nodes = self::providerBoot($nodes, $provider);
            if ($nodes === null) {
                return null;
            }
        }
        $directives = [];
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
            $arguments = self::arguments(
                $call->args,
                $method === 'directive'
                    ? ['name', 'handler', 'bind']
                    : ['name', 'callback'],
            );
            if (
                ! in_array($method, ['directive', 'if'], true)
                || $arguments === null
                || ! isset($arguments['name'])
                || ! $arguments['name'] instanceof Node\Scalar\String_
            ) {
                return null;
            }
            $nameNode = $arguments['name'];
            $name = $nameNode->value;
            if (! self::validName($name)) {
                return null;
            }
            $handler = $arguments[$method === 'directive' ? 'handler' : 'callback'] ?? null;
            if (! $handler instanceof Node\Expr\Closure && ! $handler instanceof Node\Expr\ArrowFunction) {
                return null;
            }
            if ($method === 'directive') {
                if (isset($arguments['bind']) && ! is_bool(PhpSource::value($arguments['bind']))) {
                    return null;
                }
                $directives[$name] = self::declaration($name, 'directive', $path, $nameNode, $handler->params !== []);
                continue;
            }
            if (count($arguments) !== 2) {
                return null;
            }
            foreach ([
                'if' => $name,
                'unless' => 'unless'.$name,
                'else' => 'else'.$name,
                'end' => 'end'.$name,
            ] as $kind => $generated) {
                if (! self::validName($generated)) {
                    return null;
                }
                $directives[$generated] = self::declaration($generated, $kind, $path, $nameNode, $kind !== 'end');
            }
        }

        return $directives;
    }

    private static function declaration(
        string $name,
        string $kind,
        string $path,
        Node\Scalar\String_ $node,
        bool $declaresExpressionParameter,
    ): BladeDirectiveDeclaration {
        return new BladeDirectiveDeclaration(
            $name,
            $kind,
            str_replace('\\', '/', $path),
            $node->getStartFilePos(),
            $node->getEndFilePos() + 1,
            $declaresExpressionParameter,
        );
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

    private static function validName(string $name): bool
    {
        return preg_match('/^\w+(?:::\w+)?$/D', $name) === 1;
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
