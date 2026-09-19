<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Reads only explicitly selected Blade directories, without compiling templates. */
final class BladeAnonymousComponentCatalog
{
    private const MAX_ROOTS = 128;
    private const MAX_DEPTH = 32;
    private const MAX_ENTRIES = 4096;

    /** @var list<BladeAnonymousComponent>|null */
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
        $configuration = is_array($settings) ? $settings['blade-anonymous-components'] ?? null : null;
        /** @var mixed $roots */
        $roots = is_array($configuration) ? $configuration['roots'] ?? null : null;
        $project = realpath($projectRoot);
        if (
            $project === false
            || ! is_array($roots)
            || ! array_is_list($roots)
            || count($roots) > self::MAX_ROOTS
        ) {
            return;
        }
        $project = str_replace('\\', '/', $project);
        $found = [];
        $propsParser = new BladePropsParser;
        /** @var mixed $root */
        foreach ($roots as $root) {
            if (! is_array($root) || array_diff(array_keys($root), ['path', 'prefix']) !== []) {
                return;
            }
            /** @var mixed $relative */
            $relative = $root['path'] ?? null;
            /** @var mixed $prefix */
            $prefix = $root['prefix'] ?? null;
            if (
                ! is_string($relative)
                || $relative === ''
                || str_contains($relative, "\0")
                || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])(?:\.|\.\.)(?:[/\\\\]|$)~', $relative) === 1
                || $prefix !== null
                && (! is_string($prefix)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $prefix) !== 1)
            ) {
                return;
            }
            $directory = realpath($project.'/'.$relative);
            if ($directory === false || ! is_dir($directory) || ! is_readable($directory)) {
                return;
            }
            $directory = str_replace('\\', '/', $directory);
            if (! str_starts_with($directory.'/', $project.'/')) {
                return;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                $iterator->setMaxDepth(self::MAX_DEPTH);
                $visited = 0;
                foreach ($iterator as $file) {
                    if (++$visited > self::MAX_ENTRIES || ! $file instanceof \SplFileInfo || $file->isLink()) {
                        return;
                    }
                    // A directory at the limit may hide deeper entries. Do not return a partial catalog.
                    if ($file->isDir() && $iterator->getDepth() >= self::MAX_DEPTH) {
                        return;
                    }
                    if (! $file->isFile()) {
                        continue;
                    }
                    $absolute = str_replace('\\', '/', $file->getPathname());
                    $real = $file->getRealPath();
                    if ($real === false || ! str_starts_with(str_replace('\\', '/', $real), $directory.'/')) {
                        return;
                    }
                    $inside = substr($absolute, strlen($directory) + 1);
                    if (! str_ends_with($inside, '.blade.php')) {
                        continue;
                    }
                    $stem = substr($inside, 0, -10);
                    if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $stem) !== 1) {
                        continue;
                    }
                    $segments = explode('/', $stem);
                    $name = implode('.', $segments);
                    $candidates = [$name];
                    $last = array_pop($segments);
                    if ($segments !== [] && ($last === 'index' || $last === end($segments))) {
                        $candidates[] = implode('.', $segments);
                    }
                    $found[] = new BladeAnonymousComponent(
                        substr($absolute, strlen($project) + 1),
                        $relative,
                        $prefix,
                        $name,
                        $candidates,
                        ($source = @file_get_contents($absolute, false, null, 0, 262145)) === false
                            ? null
                            : $propsParser->parse($source),
                    );
                }
            } catch (\UnexpectedValueException) {
                return;
            }
        }
        usort(
            $found,
            static fn (BladeAnonymousComponent $a, BladeAnonymousComponent $b): int => (
                strcmp($a->path, $b->path) ?: strcmp($a->rootPath, $b->rootPath) ?: strcmp(
                    $a->prefix ?? '',
                    $b->prefix ?? '',
                )
            ),
        );
        $this->components = $found;
    }

    /**
     * A source snapshot, not an assertion that Blade has registered these roots.
     * Null means unconfigured, invalid or unreadable roots.
     *
     * @return list<BladeAnonymousComponent>|null
     */
    public function components(): ?array
    {
        return $this->components;
    }
}
