# Source query cache

Laramago can register separate plugins that inspect the same PHP source snapshot.
`CodebaseScanSyntax` reuses namespace-resolved syntax and pure extraction results
within one worker process. Every plugin still receives its own scan callbacks.

Syntax entries use the exact source SHA-256 and retain at most 64 MiB of serialized
trees or 1024 entries. Extraction entries use the exact source SHA-256 together
with a policy ID and retain at most 8 MiB of serialized results or 8192 entries.
Both limits use least-recently-used eviction. Oversized results are returned but
not retained, and do not evict reusable entries. These bounds cover serialized
payloads; PHP object and array overhead and currently active results are additional.

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

Factory mapping queries distinguish native framework declarations from application
class bodies. Refreshed model proofs attach the current file, root, disk path and
source hash after extraction. Defensive boundary certificates contain scalar
source proofs and a syntax-level Request registration hazard; the native
FoundationServiceProvider path exemption is applied independently afterwards.

The cache does not store native SDK metadata, disk freshness, root/path identity,
batch completion, cancellation or failure state. Existing source size bounds,
duplicate path checks, complete-generation checks and native contract validation
remain in each scanner. A warm cache does not certify an incomplete or cancelled
scan or admit a changed framework source. Worker request deadlines are unchanged.

Run `composer check` for the package suite. Focused controls are
`tests/codebase-scan-syntax.php`, `tests/codebase-scan-query.php` and
`tests/codebase-scan-consumers.php`. The native factory, refreshed-model and
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
