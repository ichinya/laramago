<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit PSR-4-shaped component roots, parsed without Composer or application execution. */
final class BladeClassComponentCatalog
{
    /** @var array<string, array{node: Node\Stmt\Class_, path: string}> */
    private array $classes = [];
    /** @var list<BladeClassComponent>|null */
    private ?array $components = null;

    public function __construct(string $projectRoot)
    {
        $text = @file_get_contents($projectRoot.'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $configuration */
        $configuration = is_array($settings) ? $settings['blade-class-components'] ?? null : null;
        /** @var mixed $roots */
        $roots = is_array($configuration) ? $configuration['roots'] ?? null : null;
        $project = realpath($projectRoot);
        if ($project === false || ! is_array($roots) || ! array_is_list($roots)) {
            return;
        }
        $project = str_replace('\\', '/', $project);
        $source = new PhpSource($project);
        /** @var mixed $root */
        foreach ($roots as $root) {
            if (! is_array($root)) {
                return;
            }
            /** @var mixed $namespace */
            $namespace = $root['namespace'] ?? null;
            /** @var mixed $relative */
            $relative = $root['path'] ?? null;
            if (
                ! is_string($namespace)
                || preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~', $namespace) !== 1
                || ! is_string($relative)
                || $relative === ''
                || str_contains($relative, "\0")
                || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $relative)
            ) {
                return;
            }
            $directory = realpath($project.'/'.$relative);
            if ($directory === false || ! is_dir($directory)) {
                return;
            }
            $directory = str_replace('\\', '/', $directory);
            if (! str_starts_with($directory.'/', $project.'/')) {
                return;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS,
                ));
                $files = [];
                foreach ($iterator as $file) {
                    if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $path = str_replace('\\', '/', $file->getPathname());
                    $real = $file->getRealPath();
                    if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), $directory.'/')) {
                        return;
                    }
                    $files[] = $path;
                }
                sort($files);
                foreach ($files as $path) {
                    $nodes = $source->read($path);
                    if ($nodes === null) {
                        return;
                    }
                    $expected = $namespace.'\\'.str_replace('/', '\\', substr($path, strlen($directory) + 1, -4));
                    foreach ($nodes as $node) {
                        foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                            if (
                                ! $statement instanceof Node\Stmt\Class_
                                || $statement->namespacedName?->toString() !== $expected
                            ) {
                                continue;
                            }
                            $key = strtolower($expected);
                            if (isset($this->classes[$key])) {
                                return;
                            }
                            $this->classes[$key] = ['node' => $statement, 'path' => $path];
                        }
                    }
                }
            } catch (\UnexpectedValueException) {
                return;
            }
        }
        $this->components = [];
        foreach ($this->classes as $class) {
            $node = $class['node'];
            if ($node->isAbstract() || $node->namespacedName === null) {
                continue;
            }
            $name = $node->namespacedName->toString();
            $hierarchy = $this->hierarchy($node, []);
            if ($hierarchy === null) {
                continue;
            }
            $this->components[] = $this->describe($name, $class['path'], $hierarchy);
        }
    }

    /**
     * A source snapshot only. Missing classes never prove invalid component names.
     * Null means unconfigured, invalid, ambiguous or unreadable roots.
     *
     * @return list<BladeClassComponent>|null
     */
    public function components(): ?array
    {
        return $this->components;
    }

    /** @param list<string> $seen
     * @return list<Node\Stmt\Class_>|null Child first; framework Component is the boundary.
     */
    private function hierarchy(Node\Stmt\Class_ $node, array $seen): ?array
    {
        $name = strtolower($node->namespacedName?->toString() ?? '');
        if ($name === '' || in_array($name, $seen, true) || $node->extends === null) {
            return null;
        }
        $parent = strtolower($node->extends->toString());
        if ($parent === 'illuminate\\view\\component') {
            return [$node];
        }
        if (! isset($this->classes[$parent])) {
            return null;
        }
        $ancestors = $this->hierarchy($this->classes[$parent]['node'], [...$seen, $name]);

        return $ancestors === null ? null : [$node, ...$ancestors];
    }

    /** @param list<Node\Stmt\Class_> $hierarchy */
    private function describe(string $class, string $path, array $hierarchy): BladeClassComponent
    {
        foreach ($hierarchy as $node) {
            if ($node->getTraitUses() !== []) {
                return new BladeClassComponent($class, $path, null, null);
            }
        }
        $constructor = [];
        foreach ($hierarchy as $node) {
            $method = $node->getMethod('__construct');
            if ($method === null) {
                continue;
            }
            if (! $method->isPublic() || $method->isAbstract() || $method->isStatic()) {
                $constructor = null;
                break;
            }
            foreach ($method->params as $parameter) {
                if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                    $constructor = null;
                    break;
                }
                $constructor[] = [
                    'name' => $parameter->var->name,
                    'type' => self::type($parameter->type),
                    'hasDefault' => $parameter->default !== null,
                    'variadic' => $parameter->variadic,
                    'promoted' => $parameter->isPromoted(),
                    'declaredIn' => $node->namespacedName?->toString() ?? $class,
                ];
            }
            break;
        }
        $properties = [];
        foreach (array_reverse($hierarchy) as $node) {
            $owner = $node->namespacedName?->toString() ?? $class;
            foreach ($node->getProperties() as $property) {
                if ($property->hooks !== []) {
                    return new BladeClassComponent($class, $path, $constructor, null);
                }
                foreach ($property->props as $declaration) {
                    $name = $declaration->name->toString();
                    unset($properties[$name]);
                    if ($property->isPublic() && ! $property->isStatic()) {
                        $properties[$name] = ['type' => self::type($property->type), 'declaredIn' => $owner];
                    }
                }
            }
            foreach ($node->getMethod('__construct')?->params ?? [] as $parameter) {
                if ($parameter->hooks !== []) {
                    return new BladeClassComponent($class, $path, $constructor, null);
                }
                if (
                    ! $parameter->isPromoted()
                    || ! $parameter->var instanceof Node\Expr\Variable
                    || ! is_string($parameter->var->name)
                ) {
                    continue;
                }
                unset($properties[$parameter->var->name]);
                if ($parameter->isPublic()) {
                    $properties[$parameter->var->name] = [
                        'type' => self::type($parameter->type),
                        'declaredIn' => $owner,
                    ];
                }
            }
        }

        return new BladeClassComponent($class, $path, $constructor, $properties);
    }

    private static function type(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?string
    {
        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            $inner = self::type($type->type);

            return $inner === null ? null : '?'.$inner;
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($type->types as $part) {
                $inner = self::type($part);
                if ($inner === null) {
                    return null;
                }
                $parts[] = $part instanceof Node\IntersectionType ? '('.$inner.')' : $inner;
            }

            return implode($type instanceof Node\UnionType ? '|' : '&', $parts);
        }

        return null;
    }
}
