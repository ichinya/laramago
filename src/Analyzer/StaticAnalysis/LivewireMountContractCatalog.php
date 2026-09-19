<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Positive, declaration-only metadata for selected explicit Livewire components. */
final class LivewireMountContractCatalog
{
    /** @var array<string, array{class: string, file: string, line: int, mount: ?array{line: int, parameters: list<array{name: string, type: ?string, hasDefault: bool, variadic: bool, line: int}>}, declaredPublicProperties: array<string, array{type: ?string, line: int}>}>|null */
    private ?array $contracts = null;

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
        $configuration = is_array($settings) ? $settings['livewire-mount-contracts'] ?? null : null;
        /** @var mixed $registrations */
        $registrations = is_array($settings) ? $settings['livewire-components'] ?? null : null;
        /** @var mixed $entries */
        $entries = is_array($configuration) ? $configuration['components'] ?? null : null;
        /** @var mixed $version */
        $version = is_array($configuration) ? $configuration['version'] ?? null : null;
        if (
            ! is_array($configuration)
            || array_diff(array_keys($configuration), ['version', 'components']) !== []
            || ! in_array($version, [3, 4], true)
            || ! is_array($registrations)
            || ($registrations['version'] ?? null) !== $version
            || ! is_array($entries)
            || ! array_is_list($entries)
            || count($entries) > 4096
        ) {
            return;
        }

        $registered = (new LivewireComponentCatalog($root))->components();
        if ($registered === null) {
            return;
        }
        $source = new PhpSource($root);
        $contracts = [];
        /** @var mixed $entry */
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['name', 'file']) !== []) {
                return;
            }
            /** @var mixed $name */
            $name = $entry['name'] ?? null;
            /** @var mixed $file */
            $file = $entry['file'] ?? null;
            $registeredClass = is_string($name) ? $registered[$name]['class'] ?? null : null;
            if (
                ! is_string($name)
                || $name === ''
                || ! is_string($file)
                || isset($contracts[$name])
                || ! is_string($registeredClass)
                || ($path = KernelMiddlewareDeclarations::sourcePath($root, $file)) === null
            ) {
                return;
            }
            $nodes = $source->read($path);
            $contract = $nodes === null ? null : self::declaration($nodes, $registeredClass, $path);
            if ($contract === null) {
                return;
            }
            $contracts[$name] = $contract;
        }

        $this->contracts = $contracts;
    }

    /** @return array<string, array{class: string, file: string, line: int, mount: ?array{line: int, parameters: list<array{name: string, type: ?string, hasDefault: bool, variadic: bool, line: int}>}, declaredPublicProperties: array<string, array{type: ?string, line: int}>}>|null */
    public function components(): ?array
    {
        return $this->contracts;
    }

    /** @return array{class: string, file: string, line: int, mount: ?array{line: int, parameters: list<array{name: string, type: ?string, hasDefault: bool, variadic: bool, line: int}>}, declaredPublicProperties: array<string, array{type: ?string, line: int}>}|null */
    public function component(string $name): ?array
    {
        return $this->contracts[$name] ?? null;
    }

    /** @param array<array-key, Node> $nodes
     * @return array{class: string, file: string, line: int, mount: ?array{line: int, parameters: list<array{name: string, type: ?string, hasDefault: bool, variadic: bool, line: int}>}, declaredPublicProperties: array<string, array{type: ?string, line: int}>}|null
     */
    private static function declaration(array $nodes, string $class, string $file): ?array
    {
        $found = null;
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if (
                    $statement instanceof Node\Stmt\Class_
                    && strcasecmp($statement->namespacedName?->toString() ?? '', $class) === 0
                ) {
                    if ($found !== null) {
                        return null;
                    }
                    $found = $statement;
                }
            }
        }
        // Inherited mount methods and trait-added properties need their own source proof.
        if (
            $found === null
            || $found->isAbstract()
            || strtolower($found->extends?->toString() ?? '') !== 'livewire\\component'
            || $found->getTraitUses() !== []
        ) {
            return null;
        }

        $mount = null;
        $properties = [];
        foreach ($found->stmts as $member) {
            if ($member instanceof Node\Stmt\ClassMethod && strtolower($member->name->toString()) === 'mount') {
                if (! $member->isPublic() || $member->isStatic() || $mount !== null) {
                    return null;
                }
                $parameters = [];
                foreach ($member->params as $parameter) {
                    if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                        return null;
                    }
                    $parameters[] = [
                        'name' => $parameter->var->name,
                        'type' => self::type($parameter->type),
                        'hasDefault' => $parameter->default !== null,
                        'variadic' => $parameter->variadic,
                        'line' => $parameter->getStartLine(),
                    ];
                }
                $mount = ['line' => $member->getStartLine(), 'parameters' => $parameters];
            }
            if ($member instanceof Node\Stmt\Property && $member->isPublic() && ! $member->isStatic()) {
                foreach ($member->props as $property) {
                    $properties[$property->name->toString()] = [
                        'type' => self::type($member->type),
                        'line' => $property->getStartLine(),
                    ];
                }
            }
        }

        return [
            'class' => $class,
            'file' => $file,
            'line' => $found->getStartLine(),
            'mount' => $mount,
            'declaredPublicProperties' => $properties,
        ];
    }

    private static function type(?Node $node): ?string
    {
        if ($node instanceof Node\Identifier || $node instanceof Node\Name) {
            return $node->toString();
        }
        if ($node instanceof Node\NullableType) {
            $type = self::type($node->type);

            return $type === null ? null : '?'.$type;
        }
        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            $separator = $node instanceof Node\UnionType ? '|' : '&';
            $types = [];
            foreach ($node->types as $type) {
                $value = self::type($type);
                if ($value === null) {
                    return null;
                }
                $types[] =
                    $type instanceof Node\IntersectionType && $node instanceof Node\UnionType
                        ? '('.$value.')'
                        : $value;
            }

            return implode($separator, $types);
        }

        return null;
    }
}
