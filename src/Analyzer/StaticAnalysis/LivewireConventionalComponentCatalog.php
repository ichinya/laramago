<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Source-only snapshot of explicitly asserted Livewire convention roots. */
final class LivewireConventionalComponentCatalog
{
    private const MAX_ROOTS = 64;
    private const MAX_DEPTH = 32;
    private const MAX_ENTRIES = 4096;

    /** @var array<string, array{class: ?string, viewPath: ?string, file: string, line: int}>|null */
    private ?array $components = null;

    public function __construct(string $projectRoot)
    {
        $project = realpath($projectRoot);
        $text = @file_get_contents($projectRoot.'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $settings */
        $settings = is_array($composer) && is_array($composer['extra'] ?? null)
            ? $composer['extra']['laramago'] ?? null
            : null;
        /** @var mixed $configuration */
        $configuration = is_array($settings) ? $settings['livewire-conventional'] ?? null : null;
        if ($project === false || ! is_array($configuration) || array_diff(array_keys($configuration), [
            'version',
            'class-roots',
            'view-roots',
            'component-view-roots',
            'conventional-view-prefix',
        ]) !== []) {
            return;
        }
        /** @var mixed $version */
        $version = $configuration['version'] ?? null;
        /** @var mixed $classRoots */
        $classRoots = $configuration['class-roots'] ?? null;
        /** @var mixed $viewRoots */
        $viewRoots = $configuration['view-roots'] ?? null;
        /** @var mixed $componentViewRoots */
        $componentViewRoots = $configuration['component-view-roots'] ?? [];
        /** @var mixed $viewPrefix */
        $viewPrefix = $configuration['conventional-view-prefix'] ?? null;
        if (
            ! in_array($version, [3, 4], true)
            || ! is_array($classRoots)
            || ! array_is_list($classRoots)
            || ! is_array($viewRoots)
            || ! array_is_list($viewRoots)
            || ! is_array($componentViewRoots)
            || ! array_is_list($componentViewRoots)
            || (count($classRoots) + count($viewRoots) + count($componentViewRoots)) > self::MAX_ROOTS
            || $classRoots === []
            && $componentViewRoots === []
            || $version === 3
            && count($classRoots) !== 1
            || $version === 3
            && $componentViewRoots !== []
            || ! is_string($viewPrefix)
            || ! self::viewName($viewPrefix)
        ) {
            return;
        }
        $project = str_replace('\\', '/', $project);
        $views = [];
        /** @var mixed $root */
        foreach ($viewRoots as $root) {
            if (! is_array($root) || array_diff(array_keys($root), ['path', 'prefix']) !== []) {
                return;
            }
            $directory = $this->directory($project, $root['path'] ?? null);
            /** @var mixed $prefix */
            $prefix = $root['prefix'] ?? '';
            if ($directory === null || ! is_string($prefix) || $prefix !== '' && ! self::namespaceName($prefix)) {
                return;
            }
            $views[] = ['directory' => $directory, 'prefix' => $prefix];
        }
        $source = new PhpSource($project);
        /** @var array<string, Node\Stmt\Class_> $classes */
        $classes = [];
        /** @var array<string, array{class: string, path: string, node: Node\Stmt\Class_, prefix: string, relative: string}> $candidates */
        $candidates = [];
        /** @var mixed $root */
        foreach ($classRoots as $root) {
            if (! is_array($root) || array_diff(array_keys($root), ['namespace', 'path', 'prefix']) !== []) {
                return;
            }
            /** @var mixed $namespace */
            $namespace = $root['namespace'] ?? null;
            /** @var mixed $prefix */
            $prefix = $root['prefix'] ?? '';
            $directory = $this->directory($project, $root['path'] ?? null);
            if (
                ! is_string($namespace)
                || preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~D', $namespace) !== 1
                || ! is_string($prefix)
                || $prefix !== ''
                && ! self::namespaceName($prefix)
                || $version === 3
                && $prefix !== ''
                || $directory === null
            ) {
                return;
            }
            $files = $this->files($directory, '.php');
            if ($files === null) {
                return;
            }
            foreach ($files as $path) {
                $inside = substr($path, strlen($directory) + 1, -4);
                if (preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:/[A-Za-z_][A-Za-z0-9_]*)*$~D', $inside) !== 1) {
                    continue;
                }
                $expected = $namespace.'\\'.str_replace('/', '\\', $inside);
                $nodes = $source->read($path);
                if ($nodes === null) {
                    return;
                }
                foreach ($nodes as $node) {
                    foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                        if (
                            ! $statement instanceof Node\Stmt\Class_
                            || $statement->namespacedName?->toString() !== $expected
                        ) {
                            continue;
                        }
                        $key = strtolower($expected);
                        if (isset($classes[$key])) {
                            return;
                        }
                        $classes[$key] = $statement;
                        $candidates[$key] = [
                            'class' => $expected,
                            'path' => $path,
                            'node' => $statement,
                            'prefix' => $prefix,
                            'relative' => str_replace('/', '\\', $inside),
                        ];
                    }
                }
            }
        }
        $found = [];
        foreach ($candidates as $candidate) {
            $node = $candidate['node'];
            if ($node->isAbstract() || ! $this->isComponent($node, $classes, [])) {
                continue;
            }
            $class = $candidate['class'];
            $rawSegments = explode('\\', $candidate['relative']);
            $segments = array_map(self::kebab(...), $rawSegments);
            // The v4 class Index alias rules differ from v3 and are not inferred here.
            if ($version === 4 && count($segments) > 1 && end($segments) === 'index') {
                continue;
            }
            if ($version === 3 && count($segments) > 1 && end($segments) === 'index') {
                // The name-to-class convention asks the autoloader for Index.php.
                if (end($rawSegments) !== 'Index') {
                    continue;
                }
                array_pop($segments);
            }
            $name = implode('.', $segments);
            if ($candidate['prefix'] !== '') {
                $name = $candidate['prefix'].'::'.$name;
            }
            $viewName = $this->renderViewName($node);
            if ($viewName === false && $version === 3 && ! $this->hasRender($node, $classes, [])) {
                $viewName = $viewPrefix.'.'.implode('.', $segments);
            }
            $viewPath = is_string($viewName) ? $this->resolveView($project, $viewName, $views) : null;
            if (isset($found[$name])) {
                return;
            }
            $found[$name] = [
                'class' => $class,
                'viewPath' => $viewPath,
                'file' => substr($candidate['path'], strlen($project) + 1),
                'line' => $node->getStartLine(),
            ];
        }
        if ($version === 4) {
            /** @var mixed $root */
            foreach ($componentViewRoots as $root) {
                if (! is_array($root) || array_diff(array_keys($root), ['path', 'prefix']) !== []) {
                    return;
                }
                $directory = $this->directory($project, $root['path'] ?? null);
                /** @var mixed $prefix */
                $prefix = $root['prefix'] ?? '';
                if ($directory === null || ! is_string($prefix) || $prefix !== '' && ! self::namespaceName($prefix)) {
                    return;
                }
                $files = $this->files($directory, '.blade.php');
                if ($files === null) {
                    return;
                }
                foreach ($files as $path) {
                    $inside = substr($path, strlen($directory) + 1, -10);
                    $inside = preg_replace('~(^|/)⚡~u', '$1', $inside) ?? $inside;
                    if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~Du', $inside) !== 1) {
                        continue;
                    }
                    $parts = explode('/', $inside);
                    $stem = end($parts);
                    $parent = count($parts) > 1 ? $parts[count($parts) - 2] : null;
                    if ($parent === $stem && is_file(dirname($path).'/'.$stem.'.php')) {
                        // A multi-file component requires v4's format-specific resolver.
                        continue;
                    }
                    $name = implode('.', explode('/', $inside));
                    if ($prefix !== '') {
                        $name = $prefix.'::'.$name;
                    }
                    if (isset($found[$name])) {
                        return;
                    }
                    $found[$name] = [
                        'class' => null,
                        'viewPath' => substr($path, strlen($project) + 1),
                        'file' => substr($path, strlen($project) + 1),
                        'line' => 1,
                    ];
                }
            }
        }
        ksort($found);
        $this->components = $found;
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

    /** Absence cannot disprove runtime registration or discovery elsewhere. */
    public function contains(string $name): ?bool
    {
        return isset($this->components[$name]) ? true : null;
    }

    private static function viewName(string $name): bool
    {
        return (
            preg_match(
                '/^(?:[A-Za-z_][A-Za-z0-9_-]*::)?[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*$/D',
                $name,
            ) === 1
        );
    }

    private static function namespaceName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) === 1;
    }

    private static function kebab(string $name): string
    {
        if (! ctype_lower($name)) {
            $name = preg_replace('/\s+/u', '', ucwords($name)) ?? $name;
            $name = preg_replace('/(.)(?=[A-Z])/u', '$1-', $name) ?? $name;
        }

        return strtolower($name);
    }

    private function directory(string $project, mixed $relative): ?string
    {
        if (
            ! is_string($relative)
            || $relative === ''
            || str_contains($relative, "\0")
            || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])(?:\.|\.\.)(?:[/\\\\]|$)~', $relative) === 1
        ) {
            return null;
        }
        $directory = realpath($project.'/'.$relative);
        if ($directory === false || ! is_dir($directory) || ! is_readable($directory)) {
            return null;
        }
        $directory = str_replace('\\', '/', $directory);

        return str_starts_with($directory.'/', $project.'/') ? $directory : null;
    }

    /** @return list<string>|null */
    private function files(string $directory, string $extension): ?array
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            $iterator->setMaxDepth(self::MAX_DEPTH);
            $files = [];
            $visited = 0;
            foreach ($iterator as $file) {
                if (++$visited > self::MAX_ENTRIES || ! $file instanceof \SplFileInfo || $file->isLink()) {
                    return null;
                }
                if ($file->isDir() && $iterator->getDepth() >= self::MAX_DEPTH) {
                    return null;
                }
                if (! $file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $real = $file->getRealPath();
                if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), $directory.'/')) {
                    return null;
                }
                if (str_ends_with($path, $extension)) {
                    $files[] = $path;
                }
            }
            sort($files);

            return $files;
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /** @param array<string, Node\Stmt\Class_> $classes @param list<string> $seen */
    private function isComponent(Node\Stmt\Class_ $node, array $classes, array $seen): bool
    {
        if ($node->extends === null || $node->getTraitUses() !== []) {
            return false;
        }
        $parent = strtolower($node->extends->toString());
        if ($parent === 'livewire\\component') {
            return true;
        }
        if (in_array($parent, $seen, true) || ! isset($classes[$parent])) {
            return false;
        }

        return $this->isComponent($classes[$parent], $classes, [...$seen, $parent]);
    }

    /** @param array<string, Node\Stmt\Class_> $classes @param list<string> $seen */
    private function hasRender(Node\Stmt\Class_ $node, array $classes, array $seen): bool
    {
        if ($node->getMethod('render') !== null) {
            return true;
        }
        $parent = strtolower($node->extends?->toString() ?? '');
        if ($parent === '' || in_array($parent, $seen, true) || ! isset($classes[$parent])) {
            return false;
        }

        return $this->hasRender($classes[$parent], $classes, [...$seen, $parent]);
    }

    /** False means unknown; a literal name is a syntactic candidate only. */
    private function renderViewName(Node\Stmt\Class_ $node): string|false
    {
        $render = $node->getMethod('render');
        if ($render === null || $render->stmts === null || count($render->stmts) !== 1) {
            return false;
        }
        $statement = $render->stmts[0];
        if (! $statement instanceof Node\Stmt\Return_ || ! $statement->expr instanceof Node\Expr\FuncCall) {
            return false;
        }
        $call = $statement->expr;
        if (! $call->name instanceof Node\Name || $call->name->toString() !== 'view') {
            return false;
        }
        $argument = PhpSource::argument($call->args, 0, 'view');
        if (! $argument instanceof Node\Scalar\String_ || ! self::viewName($argument->value)) {
            return false;
        }

        return $argument->value;
    }

    /** @param list<array{directory: string, prefix: string}> $roots */
    private function resolveView(string $project, string $name, array $roots): ?string
    {
        $resolved = null;
        foreach ($roots as $root) {
            $candidate = $name;
            $prefix = $root['prefix'];
            if ($prefix !== '') {
                if (! str_starts_with($candidate, $prefix.'::')) {
                    continue;
                }
                $candidate = substr($candidate, strlen($prefix) + 2);
            } elseif (str_contains($candidate, '::')) {
                continue;
            }
            $path = $root['directory'].'/'.str_replace('.', '/', $candidate).'.blade.php';
            $real = realpath($path);
            if (
                $real !== false
                && is_file($real)
                && str_starts_with(str_replace('\\', '/', $real), $root['directory'].'/')
            ) {
                $path = substr(str_replace('\\', '/', $real), strlen($project) + 1);
                if ($resolved !== null && $resolved !== $path) {
                    return null;
                }
                $resolved = $path;
            }
        }

        return $resolved;
    }
}
