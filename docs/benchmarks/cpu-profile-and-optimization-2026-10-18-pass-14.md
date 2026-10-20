# Shared call and property syntax index

Baseline is the exact [accepted forty-class XML-origin selector candidate](cpu-profile-and-optimization-2026-10-18-pass-13.md). One existing read-only source-index policy now collects original declaration facts, ordered call/property span reservations, duplicate flags and conservative XML/collection origin flags in a single walk. Four existing source compilers cache positive candidates only, then merge them with the common call ledger under their original lifecycle and ambiguity rules. XML and nonempty-collection scanners use the compact facts for files without their existing origin forms; positive or uncertain files retain the original AST proof path. The bounded prefix has at most 100001 unique reservation keys and necessarily triggers the unchanged 100000-key consumer bound. Tick declarations still suppress declaration facts without discarding unrelated reservations. Actual active owners register the same versioned policy idempotently. The active full cohort remains fourteen policies; no cache budget, native decision, source-freshness check, worker/preset configuration or GC mode changes. Eight production modules change; the complete release overlay has forty-two classes.

## Paired ordinary timings

Six alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 6 | 51.74 (50.07-53.90) | 92.43 (88.61-97.11) | 2853.24 |
| candidate | 6 | 47.97 (46.90-49.23) | 84.99 (83.25-87.50) | 2819.76 |

Observed reductions: 7.28% elapsed, 8.05% CPU. Scope peak median change: -33.48 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 51.36 | 47.32 | 8.27 | 8.24 |
| 2 | candidate / baseline | 52.11 | 47.81 | 8.01 | 9.28 |
| 3 | baseline / candidate | 53.90 | 49.23 | 7.82 | 8.34 |
| 4 | candidate / baseline | 50.07 | 48.56 | 8.00 | 8.68 |
| 5 | baseline / candidate | 50.73 | 46.90 | 7.83 | 8.40 |
| 6 | candidate / baseline | 53.13 | 48.13 | 7.67 | 8.35 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\StaticAnalysis\NonEmptyCollectionCalls::scan` | 2/2 | 2.225 | 0.068 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 2.049 | 0.036 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 5.074 | 3.462 |
| `Analyzer\ScalarOperandCompatibilityFilter::filterIssue` | 463/463 | 0.595 | 1.591 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 1.691 | 2.414 |
| `Analyzer\LiteralForeachSelectionReturnFilter::filterIssue` | 70/70 | 0.813 | 0.217 |
| `Analyzer\StringPredicateArrayProvider::getReturnType` | 56/56 | 0.613 | 1.203 |
| `Analyzer\MixManifestParseHook::analyze` | 7938/7938 | 2.724 | 2.142 |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2/2 | 11.762 | 11.317 |
| `Analyzer\ConfigurationFacadeProvider::getReturnType` | 29/29 | 0.379 | 0.012 |
| `Analyzer\CollectionOffsetGuardFilter::filterIssue` | 35/35 | 1.046 | 0.694 |
| `Analyzer\OrdinaryMixedAssignmentFilter::filterIssue` | 2356/2356 | 1.603 | 1.931 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2 | 11.317 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.006 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.992 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.462 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 2.793 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 2.537 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 2.414 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117 | 2.398 |

## Correctness and limits

All 51 targeted suites passed on native Linux with their original deadlines. The shared-outline suite adds 83 controls for independent original class facts, ordered call/property spans, duplicate ambiguity, namespace/alias/case roots, input immutability, escaping value isolation, inactive/active registration, tick behavior, bounded unique-key prefixes and genuine old/new collection states. The 217 original/new XML state and exception controls also pass. The expanded source-scan cohort has 871 controls covering all eight original scanner families and the unchanged call bound. Separate native gates cover the affected XML, collection, getter, refresh, kernel and integer families. Additional one/three-worker collection integration passes with original deadlines. The untimed complete-corpus audit compares 5800 complete old/new scanner states across 580 selected PHP files and performs 8120 isolated/batched policy comparisons and input checks, plus 580 independent original declaration-index comparisons. Seven controller protocol checks pass. All sixteen full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset.

Six-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Separate global cache profiles

Two minimal instrumented source profiles are excluded from ordinary timings. They add counters and inclusive collector timers to shared syntax methods; SDK dispatch remains uninstrumented. Collector elapsed times are not CPU savings and nested query/parse timers are not additive. GC mode stays enabled.

| Variant | AST calls | Hits | Misses | AST evictions | Query evictions | Query peak entries per worker | Query peak encoded MiB per worker |
| --- | ---: | ---: | ---: | ---: | ---: | --- | --- |
| baseline | 19845 | 18501 | 1344 | 0 | 0 | 7989, 7955 | 5.96, 5.65 |
| candidate | 17789 | 16444 | 1345 | 0 | 0 | 8002, 7941 | 3.95, 3.49 |

## Complete-corpus audit

The 580 selected PHP inputs contain 48194 distinct per-file call keys and 16567 property keys. Only 1 files have a broad XML origin and 1 have one of the existing collection origin forms. These totals cover the full 580-file audit, which includes all 531 analyzed-scope PHP files. The original eight scanner families, XML and nonempty-collection scanners retain complete old/new state. Original declaration-index fields remain exact; rootless files still reserve all unsupported call/property spans.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds after excluded warmups. Fresh scopes, pinned executables, source seals and read-only dependencies use the same protocol. Successful PHPStan caches are verified and removed outside timing. All slow observations remain included.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current shared-index candidate | 3 | 50.34 (49.54-50.41) | 89.97 (84.71-90.46) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 41.41 (40.47-41.89) | 76.98 (75.33-77.87) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 41.28 (40.60-44.44) | 76.61 (74.93-82.29) | 8373 |

Rule sets, source annotations and ignores differ. Counts do not establish equal depth; ratios from different timing studies are not multiplied.

## Repeated depth and delivery checks

The 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control and Mago with/without Laramago: 924 marked observations, 23 threshold oracles. Fixture/expectation hashes and selected findings match the previous native matrix. Entire production inventories, worker, presets and Composer runtime settings match the tested overlay/release contracts. All 584 selected original and copied input hashes match the initial snapshot. Full Composer checks and hosted CI remain unrun.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-14.json).
