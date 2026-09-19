<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Optional nearby declarations, never a proof that a reference is invalid. */
final class ViewSuggestions
{
    /** @var array<string, string>|null */
    private ?array $declarations = null;
    private bool $loaded = false;

    /** @param list<string> $paths */
    public function __construct(
        private readonly string $root,
        private readonly array $paths,
    ) {}

    /** @return list<string> */
    public function notes(string $name): array
    {
        if (strlen($name) > 128 || preg_match('/^[A-Za-z0-9_-]+(?:[.\/][A-Za-z0-9_-]+)*$/D', $name) !== 1) {
            return [];
        }
        $this->load();
        $name = str_replace('/', '.', $name);
        $limit = strlen($name) < 5 ? 1 : 2;
        $candidates = [];
        foreach ($this->declarations ?? [] as $key => $path) {
            $candidate = substr($key, 2);
            if (abs(strlen($candidate) - strlen($name)) > $limit) {
                continue;
            }
            $distance = levenshtein($name, $candidate);
            if ($distance > 0 && $distance <= $limit) {
                $candidates[] = ['name' => $candidate, 'path' => $path, 'distance' => $distance];
            }
        }
        usort(
            $candidates,
            static fn (array $a, array $b): int => $a['distance'] <=> $b['distance'] ?: strcmp($a['name'], $b['name']),
        );
        $notes = [];
        foreach (array_slice($candidates, 0, 3) as $candidate) {
            $notes[] = 'Similar view "'.$candidate['name'].'" is declared at '.$candidate['path'].'.';
        }

        return $notes;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $root = realpath($this->root);
        if ($root === false) {
            return;
        }
        $declarations = [];
        $visited = 0;
        foreach ($this->paths as $path) {
            $directory = realpath($this->root.'/'.$path);
            if ($directory === false || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
                return;
            }
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $directory,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
                $iterator->setMaxDepth(32);
                $files = [];
                foreach ($iterator as $file) {
                    if (++$visited > 4096) {
                        return;
                    }
                    if (! $file instanceof \SplFileInfo || $file->isLink() || ! $file->isFile()) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                    $matches = [];
                    if (preg_match('/^(.*?)\.(blade\.php|php|css|html)$/D', $relative, $matches) !== 1) {
                        continue;
                    }
                    if (preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/D', $matches[1]) !== 1) {
                        continue;
                    }
                    $name = str_replace('/', '.', $matches[1]);
                    if (strlen($name) > 128 || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D', $name) !== 1) {
                        continue;
                    }
                    $priority = match ($matches[2]) {
                        'blade.php' => 0,
                        'php' => 1,
                        'css' => 2,
                        default => 3,
                    };
                    $key = 'v:'.$name;
                    if (! isset($files[$key]) || $priority < $files[$key]['priority']) {
                        $files[$key] = ['path' => $relative, 'priority' => $priority];
                    }
                }
                ksort($files);
                foreach ($files as $key => $file) {
                    $declarations[$key] ??= $path.'/'.$file['path'];
                }
            } catch (\UnexpectedValueException) {
                return;
            }
        }
        $this->declarations = $declarations;
    }
}
