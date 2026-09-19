<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Files in explicitly selected public roots; no application code is loaded. */
final class PublicAssetCatalog
{
    /** @var list<array{name: string, path: string, root: string}>|null */
    private ?array $assets = null;
    private bool $loaded = false;
    private bool $complete = false;

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /** Forget the filesystem snapshot so the next lookup reads composer.json and scans again. */
    public function reset(): void
    {
        $this->assets = null;
        $this->loaded = false;
        $this->complete = false;
    }

    /**
     * Files remain in configured root order, including duplicate public names.
     * Null means the configuration or filesystem could not be safely indexed.
     *
     * @return list<array{name: string, path: string, root: string}>|null
     */
    public function assets(): ?array
    {
        $this->load();

        return $this->assets;
    }

    /**
     * True means an exact public path is indexed. False proves absence only for
     * an explicitly complete catalog. Null means no safe conclusion is possible.
     */
    public function contains(string $reference): ?bool
    {
        $name = self::referencePath($reference);
        if ($name === null) {
            return null;
        }
        $this->load();
        if ($this->assets === null) {
            return null;
        }
        foreach ($this->assets as $asset) {
            if ($asset['name'] === $name) {
                return true;
            }
        }
        foreach ($this->assets as $asset) {
            // Filesystem and serving case rules vary; a case-only miss is not proof.
            if (strcasecmp($asset['name'], $name) === 0) {
                return null;
            }
        }

        return $this->complete ? false : null;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $contents = @file_get_contents($this->projectRoot.'/composer.json');
        if ($contents === false) {
            return;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (! is_array($composer)) {
            return;
        }
        /** @var mixed $extra */
        $extra = $composer['extra'] ?? null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalogs */
        $catalogs = is_array($settings) ? $settings['reference-catalogs'] ?? null : null;
        /** @var mixed $configuration */
        $configuration = is_array($catalogs) ? $catalogs['public-assets'] ?? null : null;
        if (! is_array($configuration)) {
            return;
        }
        /** @var mixed $complete */
        $complete = $configuration['complete'] ?? false;
        /** @var mixed $paths */
        $paths = $configuration['paths'] ?? null;
        if (! is_bool($complete) || ! is_array($paths) || ! array_is_list($paths) || $paths === []) {
            return;
        }
        $project = realpath($this->projectRoot);
        if ($project === false || ! is_dir($project)) {
            return;
        }
        $assets = [];
        $visited = 0;
        /** @var mixed $path */
        foreach ($paths as $path) {
            if (! is_string($path) || ! self::relativePath($path)) {
                return;
            }
            $directory = $project;
            foreach (explode('/', $path) as $segment) {
                $directory .= DIRECTORY_SEPARATOR.$segment;
                if (is_link($directory)) {
                    return;
                }
            }
            $resolved = realpath($directory);
            if (
                $resolved === false
                || ! str_starts_with($resolved, $project.DIRECTORY_SEPARATOR)
                || ! is_dir($resolved)
                || ! is_readable($resolved)
            ) {
                return;
            }
            $files = [];
            if (! $this->scan($resolved, '', $path, 0, $visited, $files)) {
                return;
            }
            usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
            array_push($assets, ...$files);
        }
        $this->assets = $assets;
        $this->complete = $complete;
    }

    /**
     * @param list<array{name: string, path: string, root: string}> $files
     */
    private function scan(
        string $directory,
        string $prefix,
        string $root,
        int $depth,
        int &$visited,
        array &$files,
    ): bool {
        if (! is_readable($directory)) {
            return false;
        }
        try {
            $entries = new \DirectoryIterator($directory);
            foreach ($entries as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if (++$visited > 4096 || $entry->isLink()) {
                    return false;
                }
                $name = $prefix.$entry->getFilename();
                if ($entry->isDir()) {
                    if (
                        $depth >= 32
                        || ! $this->scan($entry->getPathname(), $name.'/', $root, $depth + 1, $visited, $files)
                    ) {
                        return false;
                    }
                } elseif ($entry->isFile()) {
                    if (! $entry->isReadable()) {
                        return false;
                    }
                    $files[] = ['name' => $name, 'path' => $root.'/'.$name, 'root' => $root];
                } else {
                    return false;
                }
            }
        } catch (\UnexpectedValueException) {
            return false;
        }

        return true;
    }

    private static function relativePath(string $value): bool
    {
        if ($value === '' || strlen($value) > 2048) {
            return false;
        }
        foreach (explode('/', $value) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || trim($segment) === ''
                || preg_match('/[\x00-\x1f\x7f<>:"\\\\|?*%#]/', $segment) === 1
            ) {
                return false;
            }
        }

        return true;
    }

    private static function referencePath(string $reference): ?string
    {
        // URL generators may accept already absolute URLs or preserve query
        // strings and fragments. This index only covers literal file paths.
        if (str_starts_with($reference, '//') || str_contains($reference, '://')) {
            return null;
        }
        $name = str_starts_with($reference, '/') ? substr($reference, 1) : $reference;

        return self::relativePath($name) ? $name : null;
    }
}
