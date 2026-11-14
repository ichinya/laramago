<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Reuse exact host snapshots across scan hooks without sharing mutable syntax. */
final class CodebaseScanSyntax
{
    private const BASE_BYTES = 64 * 1024 * 1024;
    private const MAX_BYTES = 160 * 1024 * 1024;
    private const MAX_ENTRIES = 1024;
    private const MAX_QUERY_BYTES = 8 * 1024 * 1024;
    // Keep the encoded-byte budget fixed while accommodating the active source cohort.
    private const MAX_QUERY_ENTRIES = 16384;

    /** @var array<string, string> Serialized resolved trees, ordered by last use. */
    private static array $trees = [];
    private static int $bytes = 0;
    /** @var array<string, int> Original serialized sizes; retain the same source cohort. */
    private static array $uncompressedSizes = [];
    private static int $uncompressedBytes = 0;

    /** @var array<string, string> Serialized pure extraction results, ordered by last use. */
    private static array $queries = [];
    private static int $queryBytes = 0;
    /** @var array<string, callable(array<array-key, Node>): mixed> Trusted, read-only source projections. */
    private static array $projections = [];
    private static int $projectionDepth = 0;
    /** @var array<string, true> Bounded source hashes whose initial read-only batch completed. */
    private static array $projectedSources = [];
    /** @var array<array-key, Node>|null Active batch only; never retained across projections. */
    private static ?array $projectionRoots = null;
    /** @var list<Node>|null Preorder of the active exact root tree. */
    private static ?array $projectionNodes = null;
    /** @var array<class-string, list<Node>> */
    private static array $projectionTypes = [];

    public static function clear(): void
    {
        self::$trees = [];
        self::$bytes = 0;
        self::$uncompressedSizes = [];
        self::$uncompressedBytes = 0;
        self::$queries = [];
        self::$queryBytes = 0;
        self::$projections = [];
        self::$projectedSources = [];
        self::$projectionRoots = self::$projectionNodes = null;
        self::$projectionTypes = [];
    }

    /** @internal Register only stateless, read-only syntax extractors during active hook initialization. */
    public static function registerProjection(string $policyId, callable $extractor): void
    {
        self::$projections[$policyId] = $extractor;
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

        if (isset(self::$projections[$policyId]) && self::$projectionDepth === 0) {
            return self::project($contents, $policyId);
        }
        $result = $extractor(self::parse($contents));
        $serialized = serialize($result);
        self::retainQuery($key, $serialized);

        return $result;
    }

    /** One owned tree serves active read-only projections; no expanded tree escapes the batch. */
    private static function project(string $contents, string $requested): mixed
    {
        $nodes = self::parse($contents);
        $sourceHash = hash('sha256', $contents);
        // Eviction is not a reason to repeat every other source policy. A later
        // requested miss owns a fresh tree and evaluates only that one policy.
        $replay = isset(self::$projectedSources[$sourceHash]);
        $answer = null;
        ++self::$projectionDepth;
        self::$projectionRoots = $nodes;
        self::$projectionNodes = null;
        self::$projectionTypes = [];
        try {
            foreach (self::$projections as $policyId => $extractor) {
                $policyId = (string) $policyId;
                if ($replay && $policyId !== $requested) { continue; }
                $key = hash('sha256', strlen($policyId).':'.$policyId.$sourceHash);
                if (isset(self::$queries[$key])) {
                    if ($policyId === $requested) { $answer = self::$queries[$key]; }
                    continue;
                }
                try {
                    $serialized = serialize($extractor($nodes));
                    self::retainQuery($key, $serialized);
                    if ($policyId === $requested) { $answer = $serialized; }
                } catch (\Throwable $failure) {
                    // A speculative failure must remain observable only when that policy is requested.
                    if ($policyId === $requested) { throw $failure; }
                }
            }
            // Record no failed requested extraction. This metadata owns no
            // nodes or source bytes and is bounded independently of cache hits.
            unset(self::$projectedSources[$sourceHash]);
            while (count(self::$projectedSources) >= self::MAX_ENTRIES) {
                unset(self::$projectedSources[array_key_first(self::$projectedSources)]);
            }
            self::$projectedSources[$sourceHash] = true;
        } finally {
            self::$projectionRoots = self::$projectionNodes = null;
            self::$projectionTypes = [];
            --self::$projectionDepth;
        }
        unset($nodes);
        // Keep a local snapshot even if another projection evicted this result.
        if ($answer === null) { throw new \LogicException('Requested source projection was removed during extraction.'); }
        return unserialize($answer, ['allowed_classes' => true]);
    }


    /** @internal Reuse preorder only for trusted read-only projections of the same root tree. */
    public static function find(array|Node $nodes, callable $filter): array
    {
        if (self::$projectionDepth === 0 || self::$projectionRoots !== $nodes) {
            return (new NodeFinder)->find($nodes, $filter);
        }
        if (self::$projectionNodes === null) {
            $ordered = [];
            $result = (new NodeFinder)->find($nodes, static function (Node $node) use (&$ordered, $filter): bool {
                $ordered[] = $node;
                return (bool) $filter($node);
            });
            self::$projectionNodes = $ordered;
            return $result;
        }
        $result = [];
        foreach (self::$projectionNodes as $node) {
            if ($filter($node)) { $result[] = $node; }
        }
        return $result;
    }

    /** @internal An early search never forces creation of a complete preorder. */
    public static function findFirst(array|Node $nodes, callable $filter): ?Node
    {
        if (self::$projectionDepth === 0 || self::$projectionRoots !== $nodes || self::$projectionNodes === null) {
            return (new NodeFinder)->findFirst($nodes, $filter);
        }
        foreach (self::$projectionNodes as $node) {
            if ($filter($node)) { return $node; }
        }
        return null;
    }

    /** @internal Type buckets share only the active read-only root ownership. */
    public static function findInstanceOf(array|Node $nodes, string $class): array
    {
        if (self::$projectionDepth === 0 || self::$projectionRoots !== $nodes) {
            return (new NodeFinder)->findInstanceOf($nodes, $class);
        }
        return self::$projectionTypes[$class] ??= self::find($nodes, static fn (Node $node): bool => $node instanceof $class);
    }

    /** @internal Preserve first-match traversal when no active complete preorder exists. */
    public static function findFirstInstanceOf(array|Node $nodes, string $class): ?Node
    {
        if (self::$projectionDepth !== 0 && self::$projectionRoots === $nodes && isset(self::$projectionTypes[$class])) {
            return self::$projectionTypes[$class][0] ?? null;
        }
        return self::findFirst($nodes, static fn (Node $node): bool => $node instanceof $class);
    }

    private static function retainQuery(string $key, string $serialized): void
    {
        $size = strlen($serialized);
        if ($size <= self::MAX_QUERY_BYTES) {
            if (isset(self::$queries[$key])) {
                self::$queryBytes -= strlen(self::$queries[$key]);
                unset(self::$queries[$key]);
            }
            while (self::$queries !== []
                && (count(self::$queries) >= self::MAX_QUERY_ENTRIES || self::$queryBytes + $size > self::MAX_QUERY_BYTES)) {
                $oldest = array_key_first(self::$queries);
                self::$queryBytes -= strlen(self::$queries[$oldest]);
                unset(self::$queries[$oldest]);
            }
            self::$queries[$key] = $serialized;
            self::$queryBytes += $size;
        }
    }

    /** @return array<array-key, Node> */
    public static function parse(string $contents): array
    {
        $limit = self::treeByteLimit();
        // ini_set() can lower the heap after a previous large-cache generation.
        // Apply the new bound before a hit can return another mutable tree.
        while (self::$trees !== [] && self::$uncompressedBytes > $limit) {
            self::evictTree();
        }
        $key = hash('sha256', $contents);
        if (isset(self::$trees[$key])) {
            $serialized = self::$trees[$key];
            unset(self::$trees[$key]);
            self::$trees[$key] = $serialized;

            /** @var array<array-key, Node> $nodes Serialized only by this process. */
            $nodes = unserialize(self::serializedTree($serialized, self::$uncompressedSizes[$key]), ['allowed_classes' => true]);

            return $nodes;
        }

        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        $serialized = serialize($nodes);
        $size = strlen($serialized);
        if ($size <= $limit) {
            while (self::$trees !== []
                && (count(self::$trees) >= self::MAX_ENTRIES || self::$uncompressedBytes + $size > $limit)) {
                self::evictTree();
            }
            $stored = self::storedTree($serialized);
            self::$trees[$key] = $stored;
            self::$bytes += strlen($stored);
            self::$uncompressedSizes[$key] = $size;
            self::$uncompressedBytes += $size;
        }

        return $nodes;
    }

    /** Optional, lossless storage only; cache eligibility still uses original sizes. */
    private static function storedTree(string $serialized): string
    {
        if (strlen($serialized) < 1024 || ! \function_exists('gzdeflate') || ! \function_exists('gzinflate')) {
            return $serialized;
        }
        $compressed = \gzdeflate($serialized, 1);
        return is_string($compressed) && strlen($compressed) + 1 < strlen($serialized)
            ? "\x01".$compressed
            : $serialized;
    }

    private static function serializedTree(string $stored, int $size): string
    {
        // Serialized root arrays begin with "a"; the binary marker is private to this cache.
        if ($stored[0] !== "\x01") {
            return $stored;
        }
        $serialized = \gzinflate(substr($stored, 1), $size);
        if (! is_string($serialized) || strlen($serialized) !== $size) {
            throw new \LogicException('The internal source syntax snapshot could not be restored.');
        }
        return $serialized;
    }

    private static function treeByteLimit(): int
    {
        $heap = ini_parse_quantity((string) ini_get('memory_limit'));
        // Keep the original budget on heaps up to 512 MiB. Larger workers may
        // spend at most an eighth of their heap, with an absolute 160 MiB cap.
        return $heap < 0 ? self::MAX_BYTES : max(self::BASE_BYTES, min(self::MAX_BYTES, intdiv($heap, 8)));
    }

    private static function evictTree(): void
    {
        $oldest = array_key_first(self::$trees);
        self::$bytes -= strlen(self::$trees[$oldest]);
        self::$uncompressedBytes -= self::$uncompressedSizes[$oldest];
        unset(self::$trees[$oldest], self::$uncompressedSizes[$oldest]);
    }
}
