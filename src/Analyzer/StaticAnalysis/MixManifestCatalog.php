<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A snapshot of explicitly located Mix manifests, without Laravel bootstrap. */
final class MixManifestCatalog
{
    /** @var array<array-key, string>|null */
    private ?array $files = null;

    /** @var array<array-key, array{status: string, entries: array<array-key, string>|null, hot: string}> */
    private array $snapshots = [];

    private string $configurationStatus = 'unconfigured';

    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * A complete status describes only the literal JSON map at this snapshot.
     * It does not prove that Laravel uses this public root or that hot mode is off.
     */
    public function status(string $manifestDirectory = ''): string
    {
        return $this->snapshot($manifestDirectory)['status'];
    }

    /** @return array<array-key, string>|null Native, unmodified manifest keys and values. */
    public function entries(string $manifestDirectory = ''): ?array
    {
        return $this->snapshot($manifestDirectory)['entries'];
    }

    /** True for an exact key, false for absence in a complete map, null otherwise. */
    public function contains(string $path, string $manifestDirectory = ''): ?bool
    {
        $entries = $this->entries($manifestDirectory);
        if ($entries === null) {
            return null;
        }

        return array_key_exists(self::requestPath($path), $entries);
    }

    public function value(string $path, string $manifestDirectory = ''): ?string
    {
        return $this->entries($manifestDirectory)[self::requestPath($path)] ?? null;
    }

    /** Project-relative path provided by the contract, or null for unknown directories. */
    public function manifestPath(string $manifestDirectory = ''): ?string
    {
        $this->loadConfiguration();
        $directory = self::directory($manifestDirectory);

        return $directory === null ? null : $this->files[$directory] ?? null;
    }

    /** Filesystem observation only; absence is not a runtime or future-state assertion. */
    public function hotFileState(string $manifestDirectory = ''): string
    {
        return $this->snapshot($manifestDirectory)['hot'];
    }

    /** Refresh composer configuration, manifest contents and hot-file observations. */
    public function reset(): void
    {
        $this->files = null;
        $this->snapshots = [];
        $this->configurationStatus = 'unconfigured';
    }

    /** @return array{status: string, entries: array<array-key, string>|null, hot: string} */
    private function snapshot(string $manifestDirectory): array
    {
        $this->loadConfiguration();
        $directory = self::directory($manifestDirectory);
        if ($directory === null || $this->files === null || ! isset($this->files[$directory])) {
            return ['status' => $this->configurationStatus, 'entries' => null, 'hot' => 'unknown'];
        }
        if (isset($this->snapshots[$directory])) {
            return $this->snapshots[$directory];
        }

        $relativePath = $this->files[$directory];
        $path = rtrim($this->root, '/\\').'/'.$relativePath;
        $parent = dirname($path);
        $safeParent = self::safeParents($this->root, $relativePath);
        $hotPath = $parent.'/hot';
        $hot = 'unknown';
        if ($safeParent && ! is_link($path) && is_dir($parent) && is_readable($parent) && ! is_link($hotPath)) {
            if (is_file($hotPath) && is_readable($hotPath)) {
                $hot = 'present';
            } elseif (! file_exists($hotPath)) {
                $hot = 'absent';
            }
        }
        $snapshot = ['status' => 'missing', 'entries' => null, 'hot' => $hot];
        if (! $safeParent || is_link($path) || is_dir($parent) && ! is_readable($parent)) {
            $snapshot['status'] = 'unreadable';
        } elseif (is_file($path)) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                $snapshot['status'] = 'unreadable';
            } else {
                try {
                    /** @var mixed $json */
                    $json = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
                    if (! $json instanceof \stdClass) {
                        $snapshot['status'] = 'invalid-shape';
                    } else {
                        // Native Mix uses json_decode(..., true): duplicate keys use
                        // the last value and numeric keys acquire PHP array semantics.
                        /** @var mixed $native */
                        $native = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                        $entries = [];
                        if (! is_array($native)) {
                            $snapshot['status'] = 'invalid-shape';
                        } else {
                            /** @var mixed $value */
                            foreach ($native as $key => $value) {
                                if (! is_string($value)) {
                                    $snapshot['status'] = 'invalid-shape';
                                    break;
                                }
                                $entries[(string) $key] = $value;
                            }
                        }
                        if ($snapshot['status'] !== 'invalid-shape') {
                            $snapshot['status'] = 'complete';
                            $snapshot['entries'] = $entries;
                        }
                    }
                } catch (\JsonException) {
                    $snapshot['status'] = 'invalid-json';
                }
            }
        } elseif (file_exists($path)) {
            $snapshot['status'] = 'unreadable';
        }

        return $this->snapshots[$directory] = $snapshot;
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
        $configuration = is_array($catalogs) ? $catalogs['mix-manifests'] ?? null : null;
        if ($configuration === null) {
            return;
        }
        /** @var mixed $files */
        $files = is_array($configuration) ? $configuration['files'] ?? null : null;
        if (! is_array($files) || ! array_is_list($files) || $files === []) {
            $this->configurationStatus = 'invalid-configuration';

            return;
        }
        /** @var mixed $file */
        foreach ($files as $file) {
            /** @var mixed $directory */
            $directory = is_array($file) ? $file['directory'] ?? null : null;
            /** @var mixed $path */
            $path = is_array($file) ? $file['path'] ?? null : null;
            if (
                ! is_string($directory)
                || ! is_string($path)
                || ($directory = self::directory($directory)) === null
                || ! self::safePath($path)
                || basename($path) !== 'mix-manifest.json'
                || isset($this->files[$directory])
            ) {
                $this->files = [];
                $this->configurationStatus = 'invalid-configuration';

                return;
            }
            $this->files[$directory] = $path;
        }
    }

    private static function requestPath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private static function directory(string $directory): ?string
    {
        if ($directory === '') {
            return '';
        }
        $directory = str_starts_with($directory, '/') ? substr($directory, 1) : $directory;

        return self::safePath($directory) ? $directory : null;
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

    private static function safeParents(string $root, string $relativePath): bool
    {
        if (is_link($root) || ! is_readable($root)) {
            return false;
        }
        $current = rtrim($root, '/\\');
        $parts = explode('/', $relativePath);
        array_pop($parts);
        foreach ($parts as $part) {
            $current .= '/'.$part;
            if (is_link($current) || is_dir($current) && ! is_readable($current)) {
                return false;
            }
        }

        return true;
    }
}
