# Compact physical-source factory bindings

Baseline is the exact [accepted twenty-four-class candidate](cpu-profile-and-optimization-2026-10-18-pass-8.md). Two production classes change. FactoryReflection still requests native class metadata before consulting source, and its PHPDoc namespace/import binding algorithm is unchanged. It now asks PhpSource for that pure binding, keyed by exact source bytes plus both the requested class and base. PhpSource shares the existing two-entry LRU between expanded trees and unexposed raw snapshots. A projection can reuse its small serialized result through the existing bounded exact-byte query cache without expanding the whole AST. A later public read expands the same frozen bytes; after expansion, projections use that mutable reader tree directly and preserve all caller edits. New or evicted readers still read physical bytes and retain the original hashes, warnings and freshness checks. No native metadata or final proof decision is cached.

## Paired ordinary timings

Four alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 67.80 (66.28-69.98) | 123.30 (121.69-126.78) | 2862.09 |
| candidate | 4 | 62.62 (61.99-65.09) | 113.88 (112.75-117.33) | 2843.43 |

Observed reductions: 7.63% elapsed, 7.64% CPU. Scope peak median change: -18.66 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 66.68 | 62.81 | 7.09 | 6.80 |
| 2 | candidate / baseline | 68.91 | 62.44 | 8.38 | 6.77 |
| 3 | baseline / candidate | 69.98 | 65.09 | 8.83 | 7.22 |
| 4 | candidate / baseline | 66.28 | 61.99 | 7.11 | 7.08 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469/3469 | 8.091 | 0.358 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 4.641 | 3.111 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 1.817 | 2.712 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4/4 | 5.694 | 4.936 |
| `Analyzer\OrdinaryMixedAssignmentFilter::filterIssue` | 2356/2356 | 2.214 | 1.607 |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2/2 | 2.238 | 2.721 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 3.195 | 2.747 |
| `Analyzer\InertiaEntryReferencesHook::analyze` | 21039/21039 | 2.590 | 3.020 |
| `Analyzer\DefaultDateArgumentFilter::scan` | 2/2 | 2.178 | 1.808 |
| `Analyzer\StringHelperProvider::getReturnType` | 2/2 | 0.260 | 0.628 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 1.953 | 2.312 |
| `Analyzer\FactoryFakerContracts::scan` | 2/2 | 3.704 | 4.058 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4 | 4.936 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.058 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 3.924 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263 | 3.800 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.731 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.111 |
| `Analyzer\InertiaEntryReferencesHook::analyze` | 21039 | 3.020 |
| `Analyzer\StaticAnalysis\DefensiveArrayGuardProofs::scan` | 2 | 2.892 |

## Correctness and limits

All 32 targeted suites passed on native Linux with their original deadlines. The new source-projection suite passes 219 controls against an independent previous-reader model using fresh parses and two expanded snapshots. Mixed reads/projections preserve whole serialized trees, LRU order, hashes, warnings, deletion and repair behavior, failed snapshots, reader mutations, shared-result isolation, identical-size/mtime edits, cache resets, extraction/serialization exception identity and the two-entry bound. The 582 binding-event controls preserve the independent full-tree PHPDoc oracle. Existing native factory, model, nullable/tuple, callback, refresh, boundary and physical-source freshness suites also pass with their original deadlines. Seven controller protocol checks pass. All twelve full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Four-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current twenty-four-class candidate | 3 | 60.89 (60.56-61.89) | 110.63 (109.75-113.02) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 41.89 (40.95-43.22) | 77.74 (76.56-80.73) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 42.97 (41.67-43.94) | 77.81 (77.11-79.70) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Factory resolution CPU sections

Separate full-workload profiles preserve all 13,100 diagnostic records, source/dependency seals and two terminal workers with zero active calls. These instrumented observations are excluded from ordinary speed samples. Sections below are nested and must not be added to each other or to SDK callback totals. The candidate has additional project/parseSnapshot instrumentation, so small section deltas are descriptive.

| Operation within EloquentFactoryProvider | Calls baseline/candidate | Baseline CPU, s | Candidate CPU, s |
| --- | ---: | ---: | ---: |
| factory-provider | 3469/3469 | 8.490 | 0.461 |
| factory-model-factory | 2609/2609 | 8.197 | 0.368 |
| factory-generic-binding | 11159/11159 | 8.050 | 0.257 |
| php-source-read | 3464/0 | 5.044 | 0.000 |
| php-source-project | 0/3464 | 0.000 | 0.228 |
| factory-generic-binding-traverse | 3464/35 | 2.917 | 0.016 |
| factory-method | 6098/6098 | 0.048 | 0.038 |
| factory-inherits | 3469/3469 | 0.021 | 0.016 |

The initial profiling attempt stopped during private instrumentation preparation because one method uses a different brace layout. It started zero analyzer jobs and contributed no workload observation or speed sample. The corrected frozen probe is the baseline above; its receipt is retained separately.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-9.json).
