<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Explicit effective hint paths, without executing package service providers. */
final class ViewNamespaceCatalog
{
    /** @var array<string, list<string>> */
    private array $hints = [];

    public function __construct(
        private readonly string $root,
        mixed $configuration,
    ) {
        if (! is_array($configuration)) {
            return;
        }
        $hints = [];
        /** @var mixed $paths */
        foreach ($configuration as $namespace => $paths) {
            if (
                ! is_string($namespace)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $namespace) !== 1
                || ! is_array($paths)
                || ! array_is_list($paths)
                || $paths === []
            ) {
                return;
            }
            $valid = [];
            /** @var mixed $path */
            foreach ($paths as $path) {
                if (! is_string($path) || ! self::safePath($path)) {
                    return;
                }
                $resolved = realpath($root.'/'.$path);
                $base = realpath($root);
                if (
                    $base === false
                    || $resolved === false
                    || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)
                    || ! is_dir($resolved)
                    || ! is_readable($resolved)
                ) {
                    return;
                }
                $valid[] = $resolved;
            }
            $hints[$namespace] = $valid;
        }
        $this->hints = $hints;
    }

    public function missing(string $name): bool
    {
        $parts = explode('::', $name);
        if (
            count($parts) !== 2
            || ! isset($this->hints[$parts[0]])
            || preg_match('/^[A-Za-z0-9_-]+(?:[.\/][A-Za-z0-9_-]+)*$/D', $parts[1]) !== 1
        ) {
            return false;
        }
        foreach ($this->hints[$parts[0]] as $root) {
            foreach (['blade.php', 'php', 'css', 'html'] as $extension) {
                $exists = $this->exists($root, str_replace('.', '/', $parts[1]).'.'.$extension);
                if ($exists !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Null means a directory could not be inspected reliably. */
    private function exists(string $root, string $relative): ?bool
    {
        $path = $root;
        foreach (explode('/', $relative) as $segment) {
            $entries = @scandir($path);
            if ($entries === false) {
                return null;
            }
            if (! in_array($segment, $entries, true)) {
                foreach ($entries as $entry) {
                    if (strcasecmp($entry, $segment) === 0) {
                        return null;
                    }
                }

                return false;
            }
            $path .= '/'.$segment;
            if (is_link($path)) {
                return null;
            }
        }

        // FileViewFinder tests filesystem existence, not regular-file status.
        return file_exists($path);
    }

    private static function safePath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9_. -]+$/D', $segment) !== 1
                || trim($segment) === ''
            ) {
                return false;
            }
        }

        return true;
    }
}
