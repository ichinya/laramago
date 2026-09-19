<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit closed-world catalogs; missing declarations never enable diagnostics. */
final class ReferenceCatalogs
{
    /** @var list<string> */
    private array $views = [];
    private ?ViewNamespaceCatalog $viewNamespaces = null;
    private ?ViewSuggestions $viewSuggestions = null;
    private ?string $translations = null;
    private ?TranslationNamespaceCatalog $translationNamespaces = null;
    private readonly PhpSource $source;
    /** @var array<string, list<string>> */
    private array $locales = [];
    /** @var list<array{name: string, path: string, root: string, extension: string}>|null */
    private ?array $inertiaPages = null;
    /** @var array<array-key, mixed>|null */
    private ?array $inertiaPageConfiguration = null;
    private bool $inertiaPagesLoaded = false;
    private bool $inertiaPagesComplete = false;
    private bool $inertiaPageNamesUnique = false;

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
        /** @var mixed $inertiaPages */
        $inertiaPages = $catalogs['inertia-pages'] ?? null;
        if (is_array($inertiaPages)) {
            $this->inertiaPageConfiguration = $inertiaPages;
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
                $this->viewNamespaces = new ViewNamespaceCatalog($root, $views['namespaces'] ?? null);
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
        $this->translationNamespaces = new TranslationNamespaceCatalog(
            $root,
            $path,
            $translations['namespaces'] ?? null,
            $this->source,
        );
    }

    public function enabled(): bool
    {
        return $this->views !== [] || $this->translations !== null;
    }

    /** @return list<array{name: string, path: string, root: string, extension: string}>|null */
    public function inertiaPages(): ?array
    {
        $this->loadInertiaPages();

        return $this->inertiaPages;
    }

    /**
     * Return true for a known exact-case name, false for an absent name in an
     * explicitly complete catalog, and null when absence is not proven.
     */
    public function containsInertiaPage(string $name): ?bool
    {
        $this->loadInertiaPages();
        if ($this->inertiaPages === null) {
            return null;
        }
        foreach ($this->inertiaPages as $page) {
            if ($page['name'] === $name) {
                return true;
            }
        }

        return $this->inertiaPagesComplete ? false : null;
    }

    /**
     * Return distinct matching files only when the application asserts that
     * its Inertia page names must be unique. Runtime resolvers may otherwise
     * intentionally select one of several matching files.
     *
     * @return list<string>|null
     */
    public function ambiguousInertiaPagePaths(string $name): ?array
    {
        $this->loadInertiaPages();
        if ($this->inertiaPages === null || ! $this->inertiaPageNamesUnique) {
            return null;
        }
        $paths = [];
        $identities = [];
        foreach ($this->inertiaPages as $page) {
            if ($page['name'] !== $name) {
                continue;
            }
            $realPath = realpath($this->root.'/'.$page['path']);
            if ($realPath === false) {
                return null;
            }
            $identity = str_replace('\\', '/', $realPath);
            if (DIRECTORY_SEPARATOR === '\\') {
                $identity = strtolower($identity);
            }
            if (! isset($identities[$identity])) {
                $identities[$identity] = true;
                $paths[] = $page['path'];
            }
        }

        return count($paths) > 1 ? $paths : null;
    }

    public function completeViews(): bool
    {
        return $this->views !== [];
    }

    public function missingView(string $name): bool
    {
        if (str_contains($name, '::')) {
            return $this->viewNamespaces?->missing($name) ?? false;
        }
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

    /** @return list<string> */
    public function viewSuggestionNotes(string $name): array
    {
        $this->viewSuggestions ??= new ViewSuggestions($this->root, $this->views);

        return $this->viewSuggestions->notes($name);
    }

    /**
     * Describe the explicit catalog contract, not an observed runtime fallback.
     *
     * @return list<string>
     */
    public function translationLookupNotes(string $locale): array
    {
        if ($this->translations === null || ! isset($this->locales[$locale])) {
            return [];
        }

        return [
            'Catalog JSON lookup: '.$this->translations.'/'.$locale.'.json (requested locale only).',
            'Configured PHP locale chain: '
                .implode(' -> ', $this->locales[$locale])
                .'; declared in composer.json at extra.laramago.reference-catalogs.translations.locales.'
                .$locale
                .'. This describes the catalog contract, not an observed runtime fallback.',
        ];
    }

    public function missingTranslation(string $key, string $locale): bool
    {
        if (str_contains($key, '::')) {
            $chain = $this->locales[$locale] ?? [];

            return $chain !== [] && ($this->translationNamespaces?->missing($key, $chain) ?? false);
        }
        if (
            $this->translations === null
            || ! isset($this->locales[$locale])
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
            // FileLoader uses array_merge, which renumbers integer JSON keys.
            // An exact object-property miss is therefore not proof of absence.
            foreach (array_keys(get_object_vars($values)) as $jsonKey) {
                if (is_int($jsonKey)) {
                    return false;
                }
            }
        }
        $parts = explode('.', $key);
        $group = array_shift($parts);
        // A JSON phrase can also select a PHP group. Refuse paths and names that
        // have different filesystem meanings across supported platforms.
        if ($group === '' || preg_match('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u', $group) !== 0) {
            return false;
        }
        foreach ($this->locales[$locale] as $fallback) {
            $file = $base.'/'.$fallback.'/'.$group.'.php';
            if (! is_file($file)) {
                continue;
            }
            // A whole-group lookup can return an array; its presence is not a
            // missing phrase, even when JSON has no exact key.
            if ($parts === []) {
                return false;
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

    private function loadInertiaPages(): void
    {
        if ($this->inertiaPagesLoaded) {
            return;
        }
        $this->inertiaPagesLoaded = true;
        $configuration = $this->inertiaPageConfiguration;
        if ($configuration === null) {
            return;
        }
        /** @var mixed $complete */
        $complete = $configuration['complete'] ?? false;
        /** @var mixed $unique */
        $unique = $configuration['unique'] ?? false;
        /** @var mixed $paths */
        $paths = $configuration['paths'] ?? null;
        /** @var mixed $extensions */
        $extensions = $configuration['extensions'] ?? null;
        if (
            ! is_bool($complete)
            || ! is_bool($unique)
            || ! is_array($paths)
            || ! array_is_list($paths)
            || $paths === []
            || ! is_array($extensions)
            || ! array_is_list($extensions)
            || $extensions === []
        ) {
            return;
        }
        $validPaths = [];
        /** @var mixed $path */
        foreach ($paths as $path) {
            if (! is_string($path) || ! self::inertiaPath($path) || ! is_dir($this->root.'/'.$path)) {
                return;
            }
            $validPaths[] = $path;
        }
        $validExtensions = [];
        /** @var mixed $extension */
        foreach ($extensions as $extension) {
            if (! is_string($extension) || preg_match('/^\.?[A-Za-z0-9][A-Za-z0-9_-]*$/D', $extension) !== 1) {
                return;
            }
            $validExtensions[] = ltrim($extension, '.');
        }
        $pages = [];
        foreach ($validPaths as $path) {
            $root = $this->root.'/'.$path;
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                $files = [];
                foreach ($iterator as $file) {
                    if (! $file instanceof \SplFileInfo || $file->isLink()) {
                        return;
                    }
                    if (! $file->isFile()) {
                        continue;
                    }
                    $extension = $file->getExtension();
                    if (! in_array($extension, $validExtensions, true)) {
                        continue;
                    }
                    $relative = substr(
                        str_replace('\\', '/', $file->getPathname()),
                        strlen(str_replace('\\', '/', $root)) + 1,
                    );
                    $files[] = [
                        'name' => substr($relative, 0, -strlen($extension) - 1),
                        'path' => $path.'/'.$relative,
                        'root' => $path,
                        'extension' => $extension,
                    ];
                }
            } catch (\RuntimeException) {
                return;
            }
            usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
            array_push($pages, ...$files);
        }
        $this->inertiaPages = $pages;
        $this->inertiaPagesComplete = $complete;
        $this->inertiaPageNamesUnique = $unique;
    }

    private static function inertiaPath(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        foreach (explode('/', $value) as $segment) {
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

    private static function path(string $value): bool
    {
        return (
            (bool) preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_. -]+)*$/D', $value)
            && ! in_array('..', explode('/', $value), true)
        );
    }
}
