# Shared source parsing and factory binding events

Baseline is the exact [accepted physical-reader candidate](cpu-profile-and-optimization-2026-10-18-pass-2.md). Two additional production classes change: PhpSource reuses CodebaseScanSyntax on a miss in its existing path/hash cache, and FactoryReflection replays namespace/import/class/trait-use events in the original preorder instead of resolving all body names again. The existing 8-MiB/32-entry shared path cache and two-file instance snapshots remain. Fresh file reads, snapshot hashes, source warnings and native metadata requests remain in their existing paths. Removing the per-reader parser also avoids constructing and retaining a parser for cache hits. No native metadata, generic binding decision or final diagnostic decision is memoized.

## Paired ordinary timings

Two alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 2 | 91.19 (89.41-92.97) | 170.72 (167.66-173.77) | 2855.60 |
| candidate | 2 | 81.14 (80.11-82.17) | 151.94 (150.31-153.57) | 2851.41 |

Observed reductions: 11.02% elapsed, 11.00% CPU. Scope peak median change: -4.19 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 92.97 | 82.17 | 6.21 | 5.51 |
| 2 | candidate / baseline | 89.41 | 80.11 | 5.52 | 5.71 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between callbacks; individual profile differences are descriptive. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469/3469 | 13.044 | 9.163 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 10.208 | 7.696 |
| `Analyzer\FactoryFakerContracts::scan` | 2/2 | 5.727 | 3.795 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263/263 | 6.160 | 4.470 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 2.540 | 3.566 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 4.077 | 3.245 |
| `Analyzer\DefaultDateArgumentFilter::scan` | 2/2 | 2.051 | 2.712 |
| `Analyzer\ConfigurationFacadeProvider::getReturnType` | 29/29 | 0.664 | 0.010 |
| `Analyzer\StaticAnalysis\ModelRefreshCalls::scan` | 2/2 | 2.305 | 1.656 |
| `Analyzer\DeprecatedMethodCompatibilityProof::scan` | 2/2 | 2.206 | 1.560 |
| `Analyzer\StaticAnalysis\NonEmptyCollectionCalls::scan` | 2/2 | 3.099 | 2.465 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 9.416 | 8.898 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 9.163 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 8.898 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 7.696 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 6.318 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263 | 4.470 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.170 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4 | 4.052 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 3.795 |

## Correctness and limits

All 36 targeted suites passed on native Linux with their original deadlines. The factory event extractor matches the prior full-tree resolver and explicit expected classes across 582 controls: all supported tag prefixes/kinds, grouped/class/function/constant imports, global and bracketed namespaces, case handling, unsupported unions, repeated tags and nested/anonymous class owner transitions. Extended source-reader controls verify independent scan/reader ASTs, unchanged reader snapshots, eviction, malformed/missing source, warnings and restoration. Native consumers include string casts, source arguments, session keys, headers, closure writes, date arguments, tuple/null-flow/array/model-property and Faker matrices. Seven controller protocol checks pass. All eight full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The entire delivered PHP-source inventory matches the frozen overlay: all unmapped sources equal the installed release, and worker/presets/Composer runtime configuration remain unchanged. All 584 selected original application inputs still match their saved bytes.

Two-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. This series alone establishes no new speed ranking against Larastan and no PHPStan-equivalent depth. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Separate full-workload internal CPU probes

These instrumented probes are excluded from ordinary timing medians. Each verifies the entire accepted 13,100-record multiset, frozen source/dependencies and two terminal workers. Sections are nested; their CPU values must not be added. Before/after probe frequency and shared load are uncontrolled; paired ordinary observations provide the separate speed comparison.

| Section in EloquentFactoryProvider | Calls before/after | CPU before, s | CPU after, s |
| --- | ---: | ---: | ---: |
| factory-model-factory | 2609/2609 | 10.783 | 8.705 |
| factory-generic-binding | 11159/11159 | 10.644 | 8.567 |
| php-source-read | 3464/3464 | 5.522 | 5.316 |
| factory-generic-binding-traverse | 3464/3464 | 5.030 | 3.154 |

## Repeated depth matrix

All 33 defect/control pairs were rerun across PHPStan levels 0-10, the separate default-config-types level-10 control and Mago with/without the extension: 924 marked observations and all 23 threshold oracles passed. Fixture/oracle bytes and every selected finding match the previous native matrix. This is a separate correctness experiment with an invented minimal Laravel application and read-only dependencies; fixture and migration bodies are not invoked by the harness.

The finite corpus does not establish a universal PHPStan-level equivalence. No fresh interleaved Larastan timing comparison was run for this thirteen-class candidate; the comparison in the previous report applies to the eleven-class candidate. No timing ratios from different series are multiplied.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-3.json).
