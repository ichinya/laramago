<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Ordered Pest class/trait declarations over an explicitly selected file catalog. */
final class PestUsesCatalog
{
    /** @var list<array{source: string, line: int, names: list<string>, targets: list<string>, files: list<string>, callStart: int, nameSpans: list<array{start: int, end: int}>, sourceHash: string}>|null */
    private ?array $declarations = null;

    public function __construct(string $root)
    {
        $base = realpath($root);
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $config */
        $config = is_array($options) ? $options['pest-uses'] ?? null : null;
        /** @var mixed $sources */
        $sources = is_array($config) ? $config['sources'] ?? null : null;
        /** @var mixed $tests */
        $tests = is_array($config) ? $config['test-files'] ?? null : null;
        if (
            $base === false
            || ! is_array($sources)
            || ! array_is_list($sources)
            || ! is_array($tests)
            || ! array_is_list($tests)
        ) {
            return;
        }
        $base = self::slash($base);
        $files = [];
        /** @var mixed $file */
        foreach ($tests as $file) {
            if (! is_string($file) || ($path = self::selectedFile($base, $file)) === null) {
                return;
            }
            $files[$path] = true;
        }

        $reader = new PhpSource($base);
        $declarations = [];
        /** @var mixed $source */
        foreach ($sources as $source) {
            if (! is_string($source) || ($path = self::selectedFile($base, $source)) === null) {
                return;
            }
            $nodes = $reader->read($path);
            if ($nodes === null || self::shadowsPestFunctions($nodes)) {
                return;
            }
            foreach ($nodes as $statement) {
                if (
                    $statement instanceof Node\Stmt\Use_
                    || $statement instanceof Node\Stmt\GroupUse
                    || $statement instanceof Node\Stmt\Nop
                    || $statement instanceof Node\Stmt\Declare_
                    && $statement->stmts === null
                ) {
                    continue;
                }
                if (! $statement instanceof Node\Stmt\Expression) {
                    continue;
                }
                $declaration = self::declaration(
                    $statement->expr,
                    $path,
                    $base,
                    array_keys($files),
                    $reader->contentHash($path) ?? '',
                );
                if ($declaration !== null) {
                    $declarations[] = $declaration;
                }
            }
        }
        $this->declarations = $declarations;
    }

    /** @return list<array{source: string, line: int, names: list<string>, targets: list<string>, files: list<string>, callStart: int, nameSpans: list<array{start: int, end: int}>, sourceHash: string}>|null */
    public function declarations(): ?array
    {
        return $this->declarations;
    }

    /** @param array<array-key, Node> $nodes */
    private static function shadowsPestFunctions(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return true;
            }
            if (
                $node instanceof Node\Stmt\Function_
                && in_array(strtolower($node->name->toString()), ['pest', 'uses'], true)
            ) {
                return true;
            }
            if ($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    if (
                        ($node->type === Node\Stmt\Use_::TYPE_FUNCTION
                        || $use->type === Node\Stmt\Use_::TYPE_FUNCTION)
                        && in_array(strtolower($use->getAlias()->toString()), ['pest', 'uses'], true)
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param list<string> $selectedFiles
     * @return array{source: string, line: int, names: list<string>, targets: list<string>, files: list<string>, callStart: int, nameSpans: list<array{start: int, end: int}>, sourceHash: string}|null
     */
    private static function declaration(
        Node\Expr $expression,
        string $source,
        string $root,
        array $selectedFiles,
        string $sourceHash,
    ): ?array {
        [$base, $methods] = PhpSource::chain($expression);
        if (
            ! $base instanceof Node\Expr\FuncCall
            || ! $base->name instanceof Node\Name
            || ! in_array(strtolower($base->name->toString()), ['uses', 'pest'], true)
        ) {
            return null;
        }
        $isPest = strtolower($base->name->toString()) === 'pest';
        $names = $isPest ? [] : self::classArguments($base->args);
        if ($names === null || $isPest && ($base->args !== [] || $methods === [])) {
            return null;
        }
        $nameSpans = $isPest ? [] : self::argumentSpans($base->args);
        $default = $isPest && basename($source) === 'Pest.php' ? dirname($source) : $source;
        $targets = [self::slash($default)];
        foreach ($methods as $index => $method) {
            if (! $method->name instanceof Node\Identifier) {
                return null;
            }
            $name = strtolower($method->name->toString());
            if ($name === 'in') {
                $paths = self::pathArguments($method->args, $source, $root);
                if ($paths === null) {
                    return null;
                }
                // Pest replaces earlier targets each time in() is called.
                $targets = $paths;
            } elseif (in_array($name, ['extend', 'extends', 'use', 'uses'], true)) {
                // Only Configuration has the plural aliases; fluent calls return UsesCall.
                if (in_array($name, ['extends', 'uses'], true) && (! $isPest || $index !== 0)) {
                    return null;
                }
                $more = self::classArguments($method->args);
                if ($more === null) {
                    return null;
                }
                array_push($names, ...$more);
                array_push($nameSpans, ...self::argumentSpans($method->args));
            } else {
                return null;
            }
        }
        $matched = [];
        foreach ($selectedFiles as $file) {
            foreach ($targets as $target) {
                if (self::matches($target, $file, $root)) {
                    $matched[] = $file;
                    break;
                }
            }
        }

        return [
            'source' => $source,
            'line' => $expression->getStartLine(),
            'names' => $names,
            'targets' => $targets,
            'files' => $matched,
            'callStart' => $base->getStartFilePos(),
            'nameSpans' => $nameSpans,
            'sourceHash' => $sourceHash,
        ];
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @return list<array{start: int, end: int}>
     */
    private static function argumentSpans(array $args): array
    {
        $spans = [];
        foreach ($args as $arg) {
            if ($arg instanceof Node\Arg) {
                $spans[] = [
                    'start' => $arg->value->getStartFilePos(),
                    'end' => $arg->value->getEndFilePos() + 1,
                ];
            }
        }

        return $spans;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @return list<string>|null
     */
    private static function classArguments(array $args): ?array
    {
        $names = [];
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->name !== null || $arg->unpack || $arg->byRef) {
                return null;
            }
            if (
                $arg->value instanceof Node\Expr\ClassConstFetch
                && (
                    $arg->value->class instanceof Node\Expr
                    || in_array(
                        strtolower($arg->value->class->toString()),
                        ['self', 'static', 'parent'],
                        true,
                    )
                )
            ) {
                return null;
            }
            $value = PhpSource::value($arg->value);
            if (
                ! is_string($value)
                || preg_match(
                    '~^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$~D',
                    $value,
                ) !== 1
            ) {
                return null;
            }
            $names[] = ltrim($value, '\\');
        }

        return $names;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @return list<string>|null
     */
    private static function pathArguments(array $args, string $source, string $root): ?array
    {
        $paths = [];
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->name !== null || $arg->unpack || $arg->byRef) {
                return null;
            }
            $value = self::pathValue($arg->value, $source);
            if ($value === null || ($path = self::target($root, $source, $value)) === null) {
                return null;
            }
            $paths[] = $path;
        }

        return $paths;
    }

    private static function pathValue(Node\Expr $expression, string $source): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }
        if ($expression instanceof Node\Scalar\MagicConst\Dir) {
            return dirname($source);
        }
        if ($expression instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::pathValue($expression->left, $source);
            $right = self::pathValue($expression->right, $source);

            return $left !== null && $right !== null ? $left.$right : null;
        }

        return null;
    }

    private static function target(string $root, string $source, string $path): ?string
    {
        $path = self::slash($path);
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '{')
            || str_contains($path, '}')
            || str_contains($path, '[')
            || str_contains($path, ']')
        ) {
            return null;
        }
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1;
        $path = $absolute ? $path : dirname($source).'/'.$path;
        $parts = explode('/', $path);
        $normal = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                if ($normal === []) {
                    return null;
                }
                array_pop($normal);
            } elseif ($part !== '.' && $part !== '') {
                $normal[] = $part;
            }
        }
        $path = (str_starts_with($path, '/') ? '/' : '').implode('/', $normal);
        if (! self::inside($root, $path)) {
            return null;
        }
        $wildcard = strcspn($path, '*?');
        if ($wildcard === strlen($path)) {
            $real = realpath($path);

            return $real !== false && self::inside($root, self::slash($real))
                ? self::slash($real)
                : null;
        }
        $slash = strrpos(substr($path, 0, $wildcard), '/');
        if ($slash === false) {
            return null;
        }
        $prefix = substr($path, 0, $slash);
        $real = realpath(rtrim($prefix, '/'));

        return $real !== false && self::inside($root, self::slash($real)) ? $path : null;
    }

    private static function matches(string $target, string $file, string $root): bool
    {
        if (! strpbrk($target, '*?')) {
            return $file === $target || str_starts_with($file, rtrim($target, '/').'/');
        }
        $relativeTarget = substr($target, strlen($root) + 1);
        $relativeFile = substr($file, strlen($root) + 1);
        $targetParts = explode('/', $relativeTarget);
        $fileParts = explode('/', $relativeFile);
        if (count($targetParts) > count($fileParts)) {
            return false;
        }
        foreach ($targetParts as $index => $part) {
            // Glob treatment of leading dots is platform-dependent. Keep only portable matches.
            if (str_starts_with($fileParts[$index], '.') && ! str_starts_with($part, '.')) {
                return false;
            }
            $pattern = preg_quote($part, '~');
            $pattern = str_replace(['\\*', '\\?'], ['[^/]*', '[^/]'], $pattern);
            if (preg_match('~^'.$pattern.'$~D', $fileParts[$index]) !== 1) {
                return false;
            }
        }

        // A globbed file target must not match a descendant of that file.
        return (
            count($targetParts) === count($fileParts)
            || is_dir($root.'/'.implode('/', array_slice($fileParts, 0, count($targetParts))))
        );
    }

    private static function selectedFile(string $root, string $file): ?string
    {
        if (
            $file === ''
            || str_contains($file, "\0")
            || preg_match('~^(?:[/\\\\]|[A-Za-z]:)|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            || ! str_ends_with($file, '.php')
        ) {
            return null;
        }
        $path = realpath($root.'/'.$file);

        return $path !== false && is_file($path) && self::inside($root, self::slash($path))
            ? self::slash($path)
            : null;
    }

    private static function inside(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    private static function slash(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
