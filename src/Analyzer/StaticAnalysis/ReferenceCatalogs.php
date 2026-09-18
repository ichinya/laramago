<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit closed-world catalogs; missing declarations never enable diagnostics. */
final class ReferenceCatalogs
{
    /** @var list<string> */
    private array $views = [];
    private ?string $translations = null;
    private readonly PhpSource $source;
    /** @var array<string, list<string>> */
    private array $locales = [];

    public function __construct(
        private readonly string $root,
    ) {
        $this->source = new PhpSource($root);
        $contents = @file_get_contents($root.'/composer.json');
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
        if (! is_array($catalogs)) {
            return;
        }
        /** @var mixed $views */
        $views = $catalogs['views'] ?? null;
        if (is_array($views) && ($views['complete'] ?? null) === true) {
            /** @var mixed $paths */
            $paths = $views['paths'] ?? null;
            if (is_array($paths) && array_is_list($paths) && $paths !== []) {
                $valid = [];
                /** @var mixed $path */
                foreach ($paths as $path) {
                    if (! is_string($path) || ! self::path($path) || ! is_dir($root.'/'.$path)) {
                        return;
                    }
                    $valid[] = $path;
                }
                $this->views = $valid;
            }
        }
        /** @var mixed $translations */
        $translations = $catalogs['translations'] ?? null;
        if (! is_array($translations) || ($translations['complete'] ?? null) !== true) {
            return;
        }
        /** @var mixed $path */
        $path = $translations['path'] ?? null;
        /** @var mixed $locales */
        $locales = $translations['locales'] ?? null;
        if (! is_string($path) || ! self::path($path) || ! is_dir($root.'/'.$path) || ! is_array($locales)) {
            return;
        }
        /** @var mixed $chain */
        foreach ($locales as $locale => $chain) {
            if (
                ! is_string($locale)
                || ! self::segment($locale)
                || ! is_array($chain)
                || ! array_is_list($chain)
                || $chain === []
                || $chain[0] !== $locale
            ) {
                return;
            }
            $valid = [];
            /** @var mixed $fallback */
            foreach ($chain as $fallback) {
                if (! is_string($fallback) || ! self::segment($fallback)) {
                    return;
                }
                $valid[] = $fallback;
            }
            $this->locales[$locale] = $valid;
        }
        $this->translations = $path;
    }

    public function enabled(): bool
    {
        return $this->views !== [] || $this->translations !== null;
    }

    public function missingView(string $name): bool
    {
        if ($this->views === [] || ! preg_match('/^[A-Za-z0-9_-]+(?:[.\/][A-Za-z0-9_-]+)*$/D', $name)) {
            return false;
        }
        foreach ($this->views as $root) {
            foreach (['blade.php', 'php', 'css', 'html'] as $extension) {
                if (is_file($this->root.'/'.$root.'/'.str_replace('.', '/', $name).'.'.$extension)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function missingTranslation(string $key, string $locale): bool
    {
        if (
            $this->translations === null
            || ! isset($this->locales[$locale])
            || ! preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/D', $key)
        ) {
            return false;
        }
        $base = $this->root.'/'.$this->translations;
        $json = $base.'/'.$locale.'.json';
        if (is_file($json)) {
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
        $parts = explode('.', $key);
        $group = array_shift($parts);
        foreach ($this->locales[$locale] as $fallback) {
            $file = $base.'/'.$fallback.'/'.$group.'.php';
            if (! is_file($file)) {
                continue;
            }
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
                foreach ($value->items as $item) {
                    if ($item->unpack || ! $item->key instanceof Node\Scalar\String_) {
                        return false;
                    }
                    // Literal dotted keys can be consumed by Arr::get before splitting.
                    if (str_contains($item->key->value, '.')) {
                        return false;
                    }
                    if ($item->key->value === $part) {
                        $next = $item->value;
                    }
                }
                if ($next === null) {
                    continue 2;
                }
                $value = $next;
            }

            return false;
        }

        return true;
    }

    private static function segment(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/D', $value);
    }

    private static function path(string $value): bool
    {
        return (
            (bool) preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_. -]+)*$/D', $value)
            && ! in_array('..', explode('/', $value), true)
        );
    }
}
