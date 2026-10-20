# Batched read-only source projections

Baseline is the exact [accepted twenty-one-class candidate](cpu-profile-and-optimization-2026-10-18-pass-7.md). Five production classes change, yielding twenty-four classes in the complete release overlay. Active FactoryResultContract, RefreshedModelProperties, StoredCallbackSourceDeclarations and DefensiveBoundaryGuardProof register five stateless read-only syntax projections. The first requested projection parses one owned resolved tree and extracts each missing result. Results are serialized before exposure and retained under the existing 8 MiB/8192-entry result budget; no expanded tree escapes the batch. Public parse and unregistered query contracts keep independent mutable trees. Initialization clears registrations; failures are never cached, speculative failures are deferred to their requested policy, nested queries cannot recursively enter the batch, and oversized results preserve the requested local snapshot. Path identity, source freshness, bounds, cancellation, duplicate declarations, native metadata and final proof decisions stay in their original consumers. Late issue-only query families are not eagerly projected.

## Paired ordinary timings

Four alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 67.97 (66.48-71.00) | 125.08 (121.86-129.95) | 2848.99 |
| candidate | 4 | 66.54 (65.64-68.03) | 121.55 (119.84-124.83) | 2853.27 |

Observed reductions: 2.11% elapsed, 2.82% CPU. Scope peak median change: +4.28 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 67.24 | 66.00 | 6.87 | 7.07 |
| 2 | candidate / baseline | 71.00 | 65.64 | 6.88 | 7.07 |
| 3 | baseline / candidate | 68.71 | 68.03 | 7.07 | 7.33 |
| 4 | candidate / baseline | 66.48 | 67.07 | 6.92 | 7.34 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4/4 | 3.430 | 0.027 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 3.768 | 1.649 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4/4 | 3.568 | 5.228 |
| `Analyzer\InertiaEntryReferencesHook::analyze` | 21039/21039 | 3.192 | 2.192 |
| `Analyzer\StringPredicateArrayProvider::getReturnType` | 56/56 | 2.058 | 1.125 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 2.904 | 3.707 |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469/3469 | 8.402 | 7.788 |
| `Analyzer\FactoryFakerContracts::scan` | 2/2 | 4.185 | 3.585 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2/2 | 4.136 | 3.567 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263/263 | 3.434 | 3.979 |
| `Analyzer\StaticAnalysis\ModelRefreshCalls::scan` | 2/2 | 1.666 | 2.156 |
| `Analyzer\MixManifestParseHook::analyze` | 7938/7938 | 2.591 | 2.115 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 7.788 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4 | 5.228 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.315 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263 | 3.979 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363 | 3.707 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 3.585 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.567 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.548 |

## Correctness and limits

All 31 targeted suites passed on native Linux with their original deadlines. Twenty-one batch controls cover result/tree mutation isolation, changed bytes, ordinary queries, registration reset, nested extraction, speculative and requested failures, serialization errors, parse failures, oversized payloads, eviction and numeric policy IDs. Sixty invented-source controls exercise the five actual registered extractors, verify their input trees remain unchanged and compare their exact serialized outputs with independent extraction. A separate untimed audit repeats those comparisons on all 580 selected PHP inputs: 5,800 controls, zero parse failures. Existing declaration/capture, source-window, factory, refresh, defensive-boundary and native integrated suites also pass. Seven controller protocol checks pass. All twelve full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Four-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current twenty-four-class candidate | 3 | 68.17 (65.71-73.42) | 125.11 (120.22-133.34) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 44.63 (42.06-54.70) | 83.21 (78.58-102.29) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 41.91 (41.41-45.45) | 77.98 (76.92-80.46) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Global syntax profile and ownership audit

These additional instrumented observations cover every shared parse/query call. They are separate from ordinary speed samples. Sections are nested and are not summed with callback totals. Five real extraction policies also pass 5,800 input-mutation and exact-result checks on 580 selected PHP files; this ownership audit is untimed.

| Variant | Shared AST calls | Hits | Misses | Evictions | AST peak per worker, MiB | Query peak per worker, MiB |
| --- | ---: | ---: | ---: | ---: | --- | --- |
| baseline | 30660 | 29323 | 1337 | 0 | 148.68, 154.06 | 2.16, 1.64 |
| candidate | 27489 | 26152 | 1337 | 0 | 148.74, 153.87 | 1.87, 1.93 |

Per-policy call/hit/miss, computed-result payloads, per-worker peaks and nested CPU sections are retained in JSON. Candidate policies may be computed speculatively before their public query call, so public misses and computed-result counts are different quantities. No timing from the ownership audit is used as a speed sample.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-8.json).
