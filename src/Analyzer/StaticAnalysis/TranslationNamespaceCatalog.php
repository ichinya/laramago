<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit final FileLoader namespace hints; never discovers or executes providers. */
final class TranslationNamespaceCatalog
{
    /** @var array<string, string> */
    private array $hints = [];

    public function __construct(
        private readonly string $root,
        private readonly string $translations,
        mixed $configuration,
        private readonly PhpSource $source,
    ) {
        if (! is_array($configuration)) {
            return;
        }
        $hints = [];
        /** @var mixed $path */
        foreach ($configuration as $name => $path) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1 || ! is_string($path)) {
                return;
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9_. -]+$/D', $segment) !== 1) {
                    return;
                }
            }
            $base = realpath($root);
            $resolved = realpath($root.'/'.$path);
            if (
                $base === false
                || $resolved === false
                || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)
                || ! is_dir($resolved)
            ) {
                return;
            }
            $hints[$name] = $resolved;
        }
        $this->hints = $hints;
    }

    /** @param non-empty-list<string> $locales */
    public function missing(string $key, array $locales): bool
    {
        $matches = [];
        if (preg_match('/^([A-Za-z0-9_-]+)::([A-Za-z0-9_-]+)\.([A-Za-z0-9_.-]+)$/D', $key, $matches) !== 1) {
            return false;
        }
        [, $namespace, $group, $item] = $matches;
        if (
            ! isset($this->hints[$namespace])
            || str_starts_with($item, '.')
            || str_contains($item, '..')
            || str_ends_with($item, '.')
        ) {
            return false;
        }
        $base = $this->root.'/'.$this->translations;
        $json = $this->file($base, [$locales[0].'.json']);
        if ($json === null) {
            return false;
        }
        if ($json !== false) {
            $contents = @file_get_contents($json);
            if ($contents === false) {
                return false;
            }
            try {
                /** @var mixed $values */
                $values = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }
            if (! $values instanceof \stdClass || property_exists($values, $key)) {
                return false;
            }
        }
        foreach ($locales as $locale) {
            foreach ([
                [$this->hints[$namespace], [$locale, $group.'.php']],
                [$base, ['vendor', $namespace, $locale, $group.'.php']],
            ] as [$directory, $segments]) {
                $file = $this->file($directory, $segments);
                if ($file === null || $file !== false && ! $this->absent($file, explode('.', $item))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $segments */
    private function file(string $base, array $segments): string|false|null
    {
        foreach ($segments as $segment) {
            $entries = @scandir($base);
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
            $base .= '/'.$segment;
            if (is_link($base)) {
                return null;
            }
        }

        return is_file($base) && is_readable($base) ? $base : null;
    }

    /** @param list<string> $parts */
    private function absent(string $file, array $parts): bool
    {
        $nodes = $this->source->read($file);
        if ($nodes === null) {
            return false;
        }
        $value = null;
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_ && $value === null) {
                $value = $node->expr;
            } elseif (! $node instanceof Node\Stmt\Nop && ! $node instanceof Node\Stmt\Declare_) {
                return false;
            }
        }
        foreach ($parts as $part) {
            if (! $value instanceof Node\Expr\Array_) {
                return false;
            }
            $next = null;
            foreach ($value->items as $entry) {
                if (
                    $entry->unpack
                    || $entry->byRef
                    || ! $entry->key instanceof Node\Scalar\String_
                    || str_contains($entry->key->value, '.')
                ) {
                    return false;
                }
                if ($entry->key->value === $part) {
                    $next = $entry->value;
                }
            }
            if ($next === null) {
                return true;
            }
            $value = $next;
        }

        return false;
    }
}
