<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Maps component keys through Laravel's lookup order, using selected source snapshots only. */
final class BladeComponentTagResolver
{
    /** @var array<string, list<BladeComponentTagTarget>> */
    private array $candidates = [];
    /** @var array<string, array{name: string, path: string}> */
    private array $classPaths = [];
    /** @var array<string, string> */
    private array $classNamespaces = [];
    private string $defaultNamespace = '';
    private bool $complete = false;

    /**
     * Pass the results of the class, anonymous and alias catalogs respectively.
     * The explicit tag configuration asserts effective registration and complete
     * coverage of competing aliases, namespaces and view roots at analysis time.
     * A null catalog or absent assertion leaves resolution unknown.
     *
     * @param list<BladeClassComponent>|null $classes
     * @param list<object>|null $anonymous Objects expose path, rootPath, prefix, relativeName and candidateNames.
     * @param array<string, string>|null $aliases
     */
    public function __construct(
        string $projectRoot,
        ?array $classes,
        ?array $anonymous,
        ?array $aliases,
        bool $aliasesComplete,
    ) {
        $text = @file_get_contents(rtrim($projectRoot, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $config */
        $config = is_array($settings) ? $settings['blade-component-tags'] ?? null : null;
        if (! is_array($config) || $classes === null || $anonymous === null || $aliases === null) {
            return;
        }
        /** @var mixed $defaultNamespace */
        $defaultNamespace = $config['default-class-namespace'] ?? null;
        /** @var mixed $namespaces */
        $namespaces = $config['class-namespaces'] ?? [];
        /** @var mixed $roots */
        $roots = $config['anonymous-roots'] ?? [];
        if (
            ! is_string($defaultNamespace)
            || ! self::namespace($defaultNamespace)
            || ! is_array($namespaces)
            || array_is_list($namespaces)
            && $namespaces !== []
            || ! is_array($roots)
            || ! array_is_list($roots)
        ) {
            return;
        }
        if (
            array_filter(
                $namespaces,
                static fn (mixed $namespace, int|string $prefix): bool => ! is_string($prefix)
                || ! self::prefix($prefix)
                || ! self::namespace($namespace),
                ARRAY_FILTER_USE_BOTH,
            ) !== []
        ) {
            return;
        }
        /** @var array<string, string> $namespaces */
        if (array_filter($roots, static fn (mixed $root): bool => ! is_array($root)) !== []) {
            return;
        }
        /** @var list<array<string, mixed>> $roots */
        /** @var array<string, array{mode: string, prefix: ?string, order: int}> $activeRoots */
        $activeRoots = [];
        $namespaceCount = 0;
        $pathCount = 0;
        foreach ($roots as $root) {
            /** @var mixed $path */
            $path = $root['path'] ?? null;
            /** @var mixed $mode */
            $mode = $root['mode'] ?? null;
            /** @var mixed $prefix */
            $prefix = $root['prefix'] ?? null;
            if (
                ! is_string($path)
                || $path === ''
                || str_contains($path, "\0")
                || ! in_array($mode, ['default', 'namespace', 'path'], true)
                || $prefix !== null
                && (! is_string($prefix)
                || ! self::prefix($prefix))
                || $mode === 'default'
                && $prefix !== null
                || $mode === 'namespace'
                && $prefix === null
            ) {
                return;
            }
            $key = self::rootKey($path);
            if (isset($activeRoots[$key])) {
                return;
            }
            $order = match ($mode) {
                'namespace' => $namespaceCount++,
                'path' => $pathCount++,
                default => 0,
            };
            $activeRoots[$key] = ['mode' => $mode, 'prefix' => $prefix, 'order' => $order];
        }
        $this->defaultNamespace = $defaultNamespace;
        $this->classNamespaces = $namespaces;
        foreach ($classes as $class) {
            $this->classPaths[strtolower($class->class)] = ['name' => $class->class, 'path' => $class->path];
        }
        foreach ($anonymous as $component) {
            /** @var array<string, mixed> $data */
            $data = get_object_vars($component);
            if (
                ! is_string($data['path'] ?? null)
                || ! is_string($data['rootPath'] ?? null)
                || ! is_string($data['relativeName'] ?? null)
                || ! is_array($data['candidateNames'] ?? null)
                || ! array_is_list($data['candidateNames'])
                || isset($data['prefix'])
                && ! is_string($data['prefix'])
            ) {
                return;
            }
            /** @var array{path: string, rootPath: string, prefix?: ?string, relativeName: string, candidateNames: list<mixed>} $data */
            $path = $data['path'];
            $rootPath = $data['rootPath'];
            $prefix = $data['prefix'] ?? null;
            $relativeName = $data['relativeName'];
            $names = $data['candidateNames'];
            $activation = $activeRoots[self::rootKey($rootPath)] ?? null;
            if ($activation === null || $activation['prefix'] !== $prefix) {
                continue;
            }
            $tagPrefix = $activation['mode'] === 'default' || $prefix === null ? '' : $prefix.'::';
            $base = match ($activation['mode']) {
                'default' => 5,
                'namespace' => 8 + (3 * $activation['order']),
                default => 8 + (3 * ($namespaceCount + $activation['order'])),
            };
            if (
                array_filter($names, static fn (mixed $name): bool => ! is_string($name)
                || ! self::key($name)) !== []
            ) {
                return;
            }
            /** @var list<string> $validNames */
            $validNames = $names;
            foreach ($validNames as $name) {
                $rank = $name === $relativeName ? 0 : (str_ends_with($relativeName, '.index') ? 1 : 2);
                $target = new BladeComponentTagTarget('anonymous', $path, $path, $base + $rank);
                $this->add($tagPrefix.$name, $target);
                // Blade's registered path search also accepts an unprefixed key.
                // Only a different :: prefix excludes that path.
                if ($activation['mode'] === 'path' && $prefix !== null) {
                    $this->add($name, $target);
                }
            }
        }
        foreach ($aliases as $alias => $class) {
            if (! self::key($alias) || $class === '') {
                return;
            }
            // An alias may target a view or a class outside the selected roots.
            // It still blocks lower-priority guesses, but cannot be resolved here.
            $path = $this->classPaths[strtolower($class)]['path'] ?? '';
            $this->add($alias, new BladeComponentTagTarget('alias', $class, $path, 0));
        }
        $this->complete = $aliasesComplete && ($config['complete'] ?? null) === true;
    }

    /** @return list<BladeComponentTagTarget> All source-backed candidates in compiler lookup order. */
    public function candidates(string $componentKey): array
    {
        if (! self::key($componentKey)) {
            return [];
        }
        $result = [...($this->candidates[$componentKey] ?? []), ...$this->classMatches($componentKey)];
        usort(
            $result,
            static fn (BladeComponentTagTarget $a, BladeComponentTagTarget $b): int => (
                $a->precedence <=> $b->precedence
            ),
        );

        return $result;
    }

    /** Unknown, invalid or ambiguous keys return null; no missing-name diagnostic is implied. */
    public function resolve(string $componentKey): ?BladeComponentTagTarget
    {
        if (! $this->complete) {
            return null;
        }
        $candidates = $this->candidates($componentKey);
        if ($candidates === [] || $candidates[0]->path === '') {
            return null;
        }

        return isset($candidates[1]) && $candidates[1]->precedence === $candidates[0]->precedence
            ? null
            : $candidates[0];
    }

    /** Accepts an isolated opening/closing x-tag; it does not parse Blade documents. */
    public function resolveTag(string $tag): ?BladeComponentTagTarget
    {
        $match = [];

        return preg_match('~^</?x-([A-Za-z0-9_.:-]+)\s*/?>$~', $tag, $match) === 1
            ? $this->resolve($match[1])
            : null;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /** @return list<BladeComponentTagTarget> */
    private function classMatches(string $componentKey): array
    {
        if (str_contains($componentKey, '::')) {
            [$prefix, $local] = explode('::', $componentKey, 2);
            $namespace = $this->classNamespaces[$prefix] ?? null;

            return $namespace === null ? [] : $this->classLookup($namespace, $local, 1);
        }

        return $this->classLookup($this->defaultNamespace, $componentKey, 3);
    }

    /** @return list<BladeComponentTagTarget> */
    private function classLookup(string $namespace, string $key, int $rank): array
    {
        if ($namespace === '' || $key === '') {
            return [];
        }
        $segments = [];
        foreach (explode('.', $key) as $piece) {
            if (preg_match('~^[A-Za-z0-9_-]+$~', $piece) !== 1) {
                return [];
            }
            $words = preg_split('~[-_]+~', $piece, -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false || $words === []) {
                return [];
            }
            $segments[] = implode('', array_map(ucfirst(...), $words));
        }
        $class = $namespace.'\\'.implode('\\', $segments);
        $found = $this->classPaths[strtolower($class)] ?? null;
        if ($found !== null) {
            return [new BladeComponentTagTarget('class', $found['name'], $found['path'], $rank)];
        }
        $nested = $class.'\\'.end($segments);
        $found = $this->classPaths[strtolower($nested)] ?? null;
        if ($found !== null) {
            return [new BladeComponentTagTarget('class', $found['name'], $found['path'], $rank + 1)];
        }

        return [];
    }

    private function add(string $key, BladeComponentTagTarget $target): void
    {
        $this->candidates[$key][] = $target;
    }

    private static function namespace(mixed $name): bool
    {
        return is_string($name) && preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~', $name) === 1;
    }

    private static function prefix(string $name): bool
    {
        return preg_match('~^[A-Za-z][A-Za-z0-9_-]*$~', $name) === 1;
    }

    private static function key(string $name): bool
    {
        return preg_match('~^[A-Za-z0-9][A-Za-z0-9_.:-]*$~', $name) === 1;
    }

    private static function rootKey(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
