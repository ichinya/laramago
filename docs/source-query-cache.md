# Source query cache

Laramago can register separate plugins that inspect the same PHP source snapshot.
`CodebaseScanSyntax` reuses namespace-resolved syntax and pure extraction results
within one worker process. Every plugin still receives its own scan callbacks.

Syntax entries use the exact source SHA-256 and retain at most 1024 entries.
Their logical serialized-byte budget starts at 64 MiB. On larger PHP heaps it
uses an eighth of the heap, with a 64 MiB floor and 160 MiB ceiling; an unlimited
heap uses the ceiling. Lowering the heap also trims an existing larger cohort
before the next lookup. Optional lossless compression reduces stored bytes
without changing which sources fit: eligibility and eviction still count the
original serialized sizes. Without zlib, storage remains uncompressed.

Extraction entries use the exact source SHA-256 together with a policy ID and
retain at most 8 MiB of serialized results or 16384 entries. Both caches use
least-recently-used eviction. Oversized results are returned but not retained,
and do not evict reusable entries. These bounds cover encoded payloads; PHP
object and array overhead and currently active results are additional.

`query(contents, policyId, extractor)` supplies the extractor with resolved nodes.
An extractor must depend only on those nodes and its policy's syntax options.
The policy ID identifies the extraction algorithm and every such option. Parse,
extraction and serialization exceptions propagate and do not become cached results.
Null, false and empty results are valid cache hits.

Results are serialized before the first return. Cache hits deserialize independent
nodes and nested arrays, preserving source positions and resolved names without
sharing mutable syntax between consumers. A fresh Laravel plugin registration
clears both caches. Cache eviction or reset cannot invalidate objects already
returned to a consumer; changed source bytes require a new extraction.

Stateless read-only projections can share one owned tree and its traversal
within an active batch. Expanded trees and traversal buckets leave that batch
when it ends, including when extraction throws. Projection results remain
serialized snapshots, so a consumer cannot mutate another consumer's nodes.

After an initial source batch completes, at most 1024 source hashes record that
its read-only policies have already run. These markers retain neither source
bytes nor nodes. If a result is later evicted, a new query evaluates only the
requested policy against a fresh owned tree instead of replaying every policy.
A failed requested extraction does not mark a new source complete. Registration
reset clears these markers together with the caches. This bookkeeping does not
certify completion of a scanner generation.

Array assertion runs, conditional array guards and argv normalization extract
source-only facts through this batch. Assertion call positions use exact
unsigned 32-bit start/end pairs under the existing 2 MB per-file guard. Paths,
source hashes, span collisions and native authority remain outside extraction.

Factory mapping queries distinguish native framework declarations from application
class bodies. Refreshed model proofs attach the current file, root, disk path and
source hash after extraction. Defensive boundary certificates contain scalar
source proofs and a syntax-level Request registration hazard; the native
FoundationServiceProvider path exemption is applied independently afterwards.

The cache does not store native SDK metadata, disk freshness, root/path identity,
scan generation completion, cancellation or failure state. Existing source size bounds,
duplicate path checks, complete-generation checks and native contract validation
remain in each scanner. A warm cache does not certify an incomplete or cancelled
scan or admit a changed framework source. Worker request deadlines are unchanged.

Run `composer check` for the package suite. Focused controls are
`tests/codebase-scan-syntax.php`, `tests/codebase-scan-query.php` and
`tests/codebase-scan-consumers.php`. `tests/source-batch-replay.php` exercises
actual byte-budget eviction, requested-only replay, bounded source markers,
exception retries, output ownership and initialization reset.
The native factory, refreshed-model and
defensive-boundary suites additionally preserve complete diagnostic records and
negative cases through the production Mago worker.

## Configuration index preparation

Configuration reads need a source-write index that traverses native metadata.
SDK metadata requests suspend the current fiber. Lazy `??=` construction alone
can therefore start the traversal again in concurrent callbacks before the first
constructor has completed. The provider prepares this index in `beforeAnalysis`
and coordinates one construction per provider and initialization generation for
workers that receive callbacks without that lifecycle hook.

Concurrent callers await the same result or receive the same construction
exception. A failed construction can be retried. Initialization wakes pending
waiters with an error; both an old constructor and a queued result reject a
changed generation. No temporary weaker type is returned while preparation is
busy. Native configuration-write vetoes and framework/facade contracts remain
unchanged. Revolt is explicitly required at the SDK's supported version range.

`tests/configuration-provider-initialization.php` tests yielding construction,
error propagation, retry and reset races, then executes real Mago with one and
three workers, including a worker without lifecycle preparation. The native
checks retain the existing 512 MiB worker limit and 120000 ms request deadline.
