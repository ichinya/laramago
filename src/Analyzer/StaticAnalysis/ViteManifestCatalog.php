<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Literal snapshots of explicitly selected Vite manifests; no application code is run. */
final class ViteManifestCatalog
{
    private const MAX_BYTES = 8388608;
    private const MAX_ENTRIES = 8192;

    /** @var array<array-key, array{path: string, hot-file: string|null}>|null */
    private ?array $files = null;

    /** @var array<array-key, array{status: string, entries: array<array-key, array<array-key, mixed>>|null, hot: string}> */
    private array $snapshots = [];

    private string $configurationStatus = 'unconfigured';

    public function __construct(
        private readonly string $root,
    ) {}

    public function status(string $buildDirectory): string
    {
        return $this->snapshot($buildDirectory)['status'];
    }

    /** @return array<array-key, array<array-key, mixed>>|null Exact decoded chunk maps, including unknown fields. */
    public function entries(string $buildDirectory): ?array
    {
        return $this->snapshot($buildDirectory)['entries'];
    }

    /** An exact key lookup; false proves absence only in this complete file snapshot. */
    public function contains(string $source, string $buildDirectory): ?bool
    {
        $entries = $this->entries($buildDirectory);

        return $entries === null ? null : array_key_exists($source, $entries);
    }

    /** @return array<array-key, mixed>|null */
    public function entry(string $source, string $buildDirectory): ?array
    {
        return $this->entries($buildDirectory)[$source] ?? null;
    }

    public function manifestPath(string $buildDirectory): ?string
    {
        $this->loadConfiguration();

        return $this->files[$buildDirectory]['path'] ?? null;
    }

    /** A filesystem observation for an explicitly named hot file, never a runtime assertion. */
    public function hotFileState(string $buildDirectory): string
    {
        return $this->snapshot($buildDirectory)['hot'];
    }

    public function reset(): void
    {
        $this->files = null;
        $this->snapshots = [];
        $this->configurationStatus = 'unconfigured';
    }

    /** @return array{status: string, entries: array<array-key, array<array-key, mixed>>|null, hot: string} */
    private function snapshot(string $buildDirectory): array
    {
        $this->loadConfiguration();
        if ($this->files === null || ! isset($this->files[$buildDirectory])) {
            return ['status' => $this->configurationStatus, 'entries' => null, 'hot' => 'unknown'];
        }
        if (isset($this->snapshots[$buildDirectory])) {
            return $this->snapshots[$buildDirectory];
        }

        $file = $this->files[$buildDirectory];
        $hot = $file['hot-file'] === null ? 'unknown' : $this->fileState($file['hot-file']);
        $snapshot = ['status' => 'missing', 'entries' => null, 'hot' => $hot];
        $relativePath = $file['path'];
        $path = rtrim($this->root, '/\\').'/'.$relativePath;
        if (! $this->safeParents($relativePath) || is_link($path) || is_dir($path)) {
            $snapshot['status'] = 'unreadable';
        } elseif (is_file($path)) {
            $size = @filesize($path);
            if ($size === false) {
                $snapshot['status'] = 'unreadable';
            } elseif ($size > self::MAX_BYTES) {
                $snapshot['status'] = 'oversized';
            } else {
                $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
                if ($contents === false) {
                    $snapshot['status'] = 'unreadable';
                } elseif (strlen($contents) > self::MAX_BYTES) {
                    $snapshot['status'] = 'oversized';
                } else {
                    $snapshot = $this->decode($contents, $hot);
                }
            }
        } elseif (file_exists($path)) {
            $snapshot['status'] = 'unreadable';
        }

        return $this->snapshots[$buildDirectory] = $snapshot;
    }

    /** @return array{status: string, entries: array<array-key, array<array-key, mixed>>|null, hot: string} */
    private function decode(string $contents, string $hot): array
    {
        $snapshot = ['status' => 'invalid-shape', 'entries' => null, 'hot' => $hot];
        try {
            /** @var mixed $object */
            $object = json_decode($contents, false, 64, JSON_THROW_ON_ERROR);
            /** @var mixed $native */
            $native = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $snapshot['status'] = $exception->getCode() === JSON_ERROR_DEPTH ? 'oversized' : 'invalid-json';

            return $snapshot;
        }
        if (! $object instanceof \stdClass || ! is_array($native)) {
            return $snapshot;
        }
        if (count($native) > self::MAX_ENTRIES) {
            $snapshot['status'] = 'oversized';

            return $snapshot;
        }
        $entries = [];
        /** @var mixed $chunk */
        foreach (get_object_vars($object) as $key => $chunk) {
            if (! $chunk instanceof \stdClass) {
                return $snapshot;
            }
            /** @var mixed $entry */
            $entry = $native[$key] ?? null;
            if (! is_array($entry) || ! is_string($entry['file'] ?? null)) {
                return $snapshot;
            }
            foreach (['imports', 'dynamicImports', 'css', 'assets'] as $field) {
                if (! property_exists($chunk, $field)) {
                    continue;
                }
                /** @var mixed $list */
                $list = $chunk->$field;
                if (! is_array($list) || ! array_is_list($list)) {
                    return $snapshot;
                }
                /** @var mixed $item */
                foreach ($list as $item) {
                    if (! is_string($item)) {
                        return $snapshot;
                    }
                }
            }
            $entries[$key] = $entry;
        }
        $snapshot['status'] = 'complete';
        $snapshot['entries'] = $entries;

        return $snapshot;
    }

    private function loadConfiguration(): void
    {
        if ($this->files !== null) {
            return;
        }
        $this->files = [];
        $contents = @file_get_contents(rtrim($this->root, '/\\').'/composer.json');
        if ($contents === false) {
            return;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->configurationStatus = 'invalid-configuration';

            return;
        }
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalogs */
        $catalogs = is_array($settings) ? $settings['reference-catalogs'] ?? null : null;
        /** @var mixed $configuration */
        $configuration = is_array($catalogs) ? $catalogs['vite-manifests'] ?? null : null;
        if ($configuration === null) {
            return;
        }
        /** @var mixed $files */
        $files = is_array($configuration) ? $configuration['files'] ?? null : null;
        if (! is_array($files) || ! array_is_list($files) || $files === [] || count($files) > 32) {
            $this->configurationStatus = 'invalid-configuration';

            return;
        }
        /** @var mixed $file */
        foreach ($files as $file) {
            /** @var mixed $directory */
            $directory = is_array($file) ? $file['build-directory'] ?? null : null;
            /** @var mixed $path */
            $path = is_array($file) ? $file['path'] ?? null : null;
            /** @var mixed $hot */
            $hot = is_array($file) ? $file['hot-file'] ?? null : null;
            if (
                ! is_string($directory)
                || ! self::safePath($directory)
                || ! is_string($path)
                || ! self::safePath($path)
                || $hot !== null
                && (! is_string($hot)
                || ! self::safePath($hot))
                || isset($this->files[$directory])
            ) {
                $this->files = [];
                $this->configurationStatus = 'invalid-configuration';

                return;
            }
            $this->files[$directory] = ['path' => $path, 'hot-file' => $hot];
        }
    }

    private function fileState(string $relativePath): string
    {
        if (! $this->safeParents($relativePath)) {
            return 'unknown';
        }
        $path = rtrim($this->root, '/\\').'/'.$relativePath;
        if (is_link($path)) {
            return 'unknown';
        }
        if (is_file($path) && is_readable($path)) {
            return 'present';
        }

        return file_exists($path) ? 'unknown' : 'absent';
    }

    private function safeParents(string $relativePath): bool
    {
        if (is_link($this->root) || ! is_dir($this->root) || ! is_readable($this->root)) {
            return false;
        }
        $current = rtrim($this->root, '/\\');
        $parts = explode('/', $relativePath);
        array_pop($parts);
        foreach ($parts as $part) {
            $current .= '/'.$part;
            if (
                is_link($current)
                || file_exists($current)
                && ! is_dir($current)
                || is_dir($current)
                && ! is_readable($current)
            ) {
                return false;
            }
        }

        return true;
    }

    private static function safePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || trim($segment) === ''
                || preg_match('/^[A-Za-z0-9_. -]+$/D', $segment) !== 1
            ) {
                return false;
            }
        }

        return true;
    }
}
