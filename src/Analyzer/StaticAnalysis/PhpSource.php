<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** Reads syntax only. Files, expressions and application classes are never executed. */
final class PhpSource
{
    private const SHARED_CACHE_BYTES = 8 * 1024 * 1024;
    private const SHARED_CACHE_ENTRIES = 32;
    private const INSTANCE_CACHE_ENTRIES = 2;

    /** @var array<string, string> Serialized, resolved ASTs in least-recently-used order. */
    private static array $sharedFiles = [];
    private static int $sharedBytes = 0;

    private readonly Parser $parser;
    /** @var array<string, array<array-key, Node>|null> */
    private array $files = [];
    /** @var array<string, string> */
    private array $contentHashes = [];
    /** @var array<string, string> */
    public array $warnings = [];

    public function __construct(
        public readonly string $root,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function path(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('~^(?:[A-Za-z]:[/\\\\]|\\\\\\\\)~', $path)
            ? $path
            : $this->root.'/'.$path;
    }

    /** Hash of the exact parsed snapshot, without rereading the file. */
    public function contentHash(string $path): ?string
    {
        return $this->contentHashes[$this->path($path)] ?? null;
    }

    /** Every syntax snapshot read by this instance still has its original bytes. */
    public function isCurrent(): bool
    {
        foreach ($this->contentHashes as $path => $hash) {
            if (@hash_file('sha256', $path) !== $hash) {
                return false;
            }
        }

        return true;
    }
    /** Start a fresh plugin registration without changing existing reader snapshots. */
    public static function clearSharedCache(): void
    {
        self::$sharedFiles = [];
        self::$sharedBytes = 0;
    }

    /** @return array<array-key, Node>|null */
    public function read(string $path): ?array
    {
        $path = $this->path($path);
        if (array_key_exists($path, $this->files)) {
            $nodes = $this->files[$path];
            unset($this->files[$path]);

            return $this->files[$path] = $nodes;
        }
        // Free the oldest AST before allocating another resolved tree. Retaining
        // it until after unserialize() or parse() increases the worker's peak.
        while (count($this->files) >= self::INSTANCE_CACHE_ENTRIES) {
            unset($this->files[array_key_first($this->files)]);
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->warnings[$path] = 'Cannot read PHP source for static model metadata.';

            return $this->files[$path] = null;
        }
        $hash = $this->contentHashes[$path] = hash('sha256', $contents);
        $key = hash('sha256', $path."\0".$hash);
        if (isset(self::$sharedFiles[$key])) {
            $serialized = self::$sharedFiles[$key];
            unset(self::$sharedFiles[$key]);
            self::$sharedFiles[$key] = $serialized;

            /** @var array<array-key, Node> $nodes The bytes were serialized by this process. */
            $nodes = unserialize($serialized, ['allowed_classes' => true]);

            return $this->retain($path, $nodes);
        }
        try {
            $nodes = $this->parser->parse($contents) ?? [];
            $resolved = (new NodeTraverser(new NameResolver))->traverse($nodes);
            $serialized = serialize($resolved);
            $size = strlen($serialized);
            if ($size <= self::SHARED_CACHE_BYTES) {
                while (
                    self::$sharedFiles !== []
                    && (count(self::$sharedFiles) >= self::SHARED_CACHE_ENTRIES
                    || (self::$sharedBytes + $size) > self::SHARED_CACHE_BYTES)
                ) {
                    $oldest = array_key_first(self::$sharedFiles);
                    self::$sharedBytes -= strlen(self::$sharedFiles[$oldest]);
                    unset(self::$sharedFiles[$oldest]);
                }
                self::$sharedFiles[$key] = $serialized;
                self::$sharedBytes += $size;
            }

            return $this->retain($path, $resolved);
        } catch (Error $error) {
            $this->warnings[$path] = 'Cannot parse PHP source for static model metadata: '.$error->getMessage();

            return $this->files[$path] = null;
        }
    }

    /**
     * Keep one parsed file in the per-instance cache under an LRU bound.
     *
     * Unbounded retention has exhausted the worker's memory budget on large
     * applications: analysis runs parse hundreds of files across long-lived provider
     * readers, so each reader evicts its least recently used snapshots.
     *
     * @param array<array-key, Node>|null $nodes
     */
    private function retain(string $path, ?array $nodes): ?array
    {
        while (count($this->files) >= self::INSTANCE_CACHE_ENTRIES) {
            unset($this->files[array_key_first($this->files)]);
        }

        return $this->files[$path] = $nodes;
    }

    /**
     * Only literal syntax is accepted, excluding function and constant evaluation.
     * Nested array values are unnecessary for casts and column names and stay unknown.
     *
     * @return scalar|array<array-key, scalar|null|UnknownValue>|null|UnknownValue
     */
    public static function value(
        ?Node $node,
        ?string $class = null,
        ?string $staticClass = null,
    ): string|int|float|bool|array|UnknownValue|null {
        if (
            $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
        ) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => UnknownValue::Value,
            };
        }
        if (
            $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'class'
        ) {
            $name = $node->class->toString();

            return match (strtolower($name)) {
                'self' => $class ?? UnknownValue::Value,
                'static' => $staticClass ?? $class ?? UnknownValue::Value,
                default => $name,
            };
        }
        if ($node instanceof Node\Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if ($item->unpack || $item->byRef) {
                    return UnknownValue::Value;
                }
                $value = self::value($item->value, $class, $staticClass);
                if (is_array($value)) {
                    $value = UnknownValue::Value;
                }
                if ($item->key === null) {
                    $result[] = $value;
                } else {
                    $key = self::value($item->key, $class, $staticClass);
                    if (! is_string($key) && ! is_int($key)) {
                        return UnknownValue::Value;
                    }
                    $result[$key] = $value;
                }
            }

            return $result;
        }

        return UnknownValue::Value;
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args */
    public static function argument(array $args, int $position, string $name): ?Node\Expr
    {
        foreach ($args as $index => $arg) {
            if (
                $arg instanceof Node\Arg
                && ($arg->name?->toString() === $name
                || $arg->name === null
                && $index === $position)
            ) {
                return $arg->unpack ? null : $arg->value;
            }
        }

        return null;
    }

    /** @return array{Node\Expr, list<Node\Expr\MethodCall>} */
    public static function chain(Node\Expr $expression): array
    {
        $calls = [];
        while ($expression instanceof Node\Expr\MethodCall) {
            $calls[] = $expression;
            $expression = $expression->var;
        }

        return [$expression, array_reverse($calls)];
    }
}
