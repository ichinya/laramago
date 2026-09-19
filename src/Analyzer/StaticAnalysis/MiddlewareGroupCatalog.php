<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Explicit effective group declarations; never bootstraps the application. */
final class MiddlewareGroupCatalog
{
    /** @var array<string, list<string>>|null */
    private ?array $groups = null;
    private bool $complete = false;

    public function __construct(string $root)
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        if ($text === false) {
            return;
        }
        /** @var mixed $composer */
        $composer = json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($options) ? $options['middleware-groups'] ?? null : null;
        if (! is_array($catalog)) {
            return;
        }
        /** @var mixed $file */
        $file = $catalog['kernel-file'] ?? null;
        /** @var mixed $class */
        $class = $catalog['kernel-class'] ?? null;
        /** @var mixed $complete */
        $complete = array_key_exists('complete', $catalog) ? $catalog['complete'] : false;
        if (! is_bool($complete)) {
            return;
        }
        $source = new PhpSource($root);
        if (array_key_exists('files', $catalog)) {
            /** @var mixed $files */
            $files = $catalog['files'];
            if (! is_array($files) || ! array_is_list($files) || $file !== null || $class !== null) {
                return;
            }
            $groups = [];
            /** @var mixed $path */
            foreach ($files as $path) {
                if (! is_string($path) || $path === '') {
                    return;
                }
                $entries = self::readFile($source, $path);
                if ($entries === null) {
                    return;
                }
                $groups = array_replace($groups, $entries);
            }
            $this->groups = $groups;
        } else {
            if (! is_string($file) || $file === '' || ! is_string($class) || $class === '') {
                return;
            }
            $this->groups = (new KernelMiddlewareDeclarations($source, $file, $class))->groups();
        }
        $this->complete = $complete;
    }

    /** @return array<string, list<string>>|null */
    private static function readFile(PhpSource $source, string $path): ?array
    {
        $resolved = KernelMiddlewareDeclarations::sourcePath($source->root, $path);
        if ($resolved === null) {
            return null;
        }
        $nodes = $source->read($resolved);
        if ($nodes === null) {
            return null;
        }
        $groups = null;
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) {
                continue;
            }
            if ($node instanceof Node\Stmt\Declare_ && $node->stmts === null) {
                continue;
            }
            if (! $node instanceof Node\Stmt\Return_ || $groups !== null) {
                return null;
            }
            $groups = KernelMiddlewareDeclarations::readGroups($node->expr);
            if ($groups === null) {
                return null;
            }
        }

        return $groups;
    }

    /** @return array<string, list<string>>|null Ordered raw entries, without expansion or alias substitution. */
    public function groups(): ?array
    {
        return $this->groups;
    }

    public function isComplete(): bool
    {
        return $this->groups !== null && $this->complete;
    }

    public function contains(string $name): ?bool
    {
        if ($this->groups === null) {
            return null;
        }

        return array_key_exists($name, $this->groups) ? true : ($this->complete ? false : null);
    }
}
