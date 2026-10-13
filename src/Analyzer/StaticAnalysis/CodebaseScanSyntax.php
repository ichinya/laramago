<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Reuse exact host snapshots across scan hooks without sharing mutable syntax. */
final class CodebaseScanSyntax
{
    private const MAX_BYTES = 64 * 1024 * 1024;
    private const MAX_ENTRIES = 1024;
    private const MAX_QUERY_BYTES = 8 * 1024 * 1024;
    private const MAX_QUERY_ENTRIES = 8192;

    /** @var array<string, string> Serialized resolved trees, ordered by last use. */
    private static array $trees = [];
    private static int $bytes = 0;

    /** @var array<string, string> Serialized pure extraction results, ordered by last use. */
    private static array $queries = [];
    private static int $queryBytes = 0;

    public static function clear(): void
    {
        self::$trees = [];
        self::$bytes = 0;
        self::$queries = [];
        self::$queryBytes = 0;
    }

    /**
     * Reuse a pure, serializable extraction from an exact resolved source tree.
     *
     * The policy ID must identify the extractor and all its syntax-level options.
     * Results must depend only on the supplied nodes: roots, paths, disk contents,
     * native/codebase state and scan lifecycle state belong outside this query.
     * Serialize before the first return so mutations never cross consumers.
     * Failed parses, extractions and serialization are never retained.
     *
     * @template TResult
     * @param callable(array<array-key, Node>): TResult $extractor
     * @return TResult
     */
    public static function query(string $contents, string $policyId, callable $extractor): mixed
    {
        $key = hash('sha256', strlen($policyId).':'.$policyId.hash('sha256', $contents));
        if (isset(self::$queries[$key])) {
            $serialized = self::$queries[$key];
            unset(self::$queries[$key]);
            self::$queries[$key] = $serialized;

            /** @var TResult $result Serialized only by this process. */
            $result = unserialize($serialized, ['allowed_classes' => true]);

            return $result;
        }

        $result = $extractor(self::parse($contents));
        $serialized = serialize($result);
        $size = strlen($serialized);
        if ($size <= self::MAX_QUERY_BYTES) {
            while (self::$queries !== []
                && (count(self::$queries) >= self::MAX_QUERY_ENTRIES || self::$queryBytes + $size > self::MAX_QUERY_BYTES)) {
                $oldest = array_key_first(self::$queries);
                self::$queryBytes -= strlen(self::$queries[$oldest]);
                unset(self::$queries[$oldest]);
            }
            self::$queries[$key] = $serialized;
            self::$queryBytes += $size;
        }

        return $result;
    }

    /** @return array<array-key, Node> */
    public static function parse(string $contents): array
    {
        $key = hash('sha256', $contents);
        if (isset(self::$trees[$key])) {
            $serialized = self::$trees[$key];
            unset(self::$trees[$key]);
            self::$trees[$key] = $serialized;

            /** @var array<array-key, Node> $nodes Serialized only by this process. */
            $nodes = unserialize($serialized, ['allowed_classes' => true]);

            return $nodes;
        }

        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        $serialized = serialize($nodes);
        $size = strlen($serialized);
        if ($size <= self::MAX_BYTES) {
            while (self::$trees !== []
                && (count(self::$trees) >= self::MAX_ENTRIES || self::$bytes + $size > self::MAX_BYTES)) {
                $oldest = array_key_first(self::$trees);
                self::$bytes -= strlen(self::$trees[$oldest]);
                unset(self::$trees[$oldest]);
            }
            self::$trees[$key] = $serialized;
            self::$bytes += $size;
        }

        return $nodes;
    }
}
