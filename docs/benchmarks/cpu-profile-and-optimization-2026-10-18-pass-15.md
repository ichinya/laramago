# Owned aggregate getter snapshots

Baseline is the exact [accepted forty-two-class shared-index candidate](cpu-profile-and-optimization-2026-10-18-pass-14.md). AggregateProjectionCalls now registers one stateless positive getter projection through its actual active provider, so its mutable get-call snapshots come from the existing owned source tree rather than another full AST copy. A new typed method/nullsafe/static dispatch ledger in the existing source-index policy preserves all unrelated spans, duplicates, preorder and the unchanged 100000-key consumer bound. First 100001 unique ledger keys necessarily force the original failure. The active full cohort has fifteen policies. The query-entry ceiling rises from 8192 to a bounded 16384 to hold the measured source working set; the encoded result budget stays 8 MiB. LRU eviction, byte accounting, independent mutable returns, parsing/extraction/serialization failures and cache reset controls pass at the new limit. No native/source-freshness proof, rule, worker/preset configuration or GC mode changes. Four production modules change; the complete release overlay has forty-three classes.

## Paired ordinary timings

Six alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 6 | 50.55 (48.12-63.00) | 89.56 (85.14-95.25) | 2830.64 |
| candidate | 6 | 47.12 (45.89-48.70) | 83.64 (81.44-86.30) | 2842.22 |

Observed reductions: 6.78% elapsed, 6.60% CPU. Scope peak median change: +11.57 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 50.26 | 47.12 | 8.26 | 8.37 |
| 2 | candidate / baseline | 53.85 | 45.89 | 9.50 | 8.34 |
| 3 | baseline / candidate | 63.00 | 48.40 | 27.50 | 9.30 |
| 4 | candidate / baseline | 48.73 | 48.70 | 8.72 | 8.72 |
| 5 | baseline / candidate | 48.12 | 46.55 | 8.37 | 8.50 |
| 6 | candidate / baseline | 50.83 | 47.11 | 10.08 | 8.66 |

## Separate callback CPU profiles

The first scan callback that requests a source projection pays for the shared fifteen-policy extraction batch. That owner changes from AssertedPureGetterCalls to AggregateProjectionCalls in this series. Their individual rows therefore include a change in cost attribution; they do not measure only each scanner's own work. Ordinary whole-process CPU and separate minimal cache counters establish the total effect.

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2/2 | 10.185 | 0.048 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2/2 | 3.608 | 11.722 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 1.618 | 2.521 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 2.040 | 2.609 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 1.453 | 1.082 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263/263 | 0.432 | 0.801 |
| `Analyzer\ConfigurationProvider::getReturnType` | 61/61 | 0.369 | 0.003 |
| `Analyzer\LiteralForeachSelectionReturnFilter::filterIssue` | 70/70 | 0.560 | 0.221 |
| `Analyzer\ConfigurationFacadeProvider::getReturnType` | 29/29 | 0.009 | 0.295 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 3.346 | 3.094 |
| `Analyzer\QueryAggregateProvider::getReturnType` | 44/44 | 0.462 | 0.220 |
| `Analyzer\CollectionOffsetGuardFilter::filterIssue` | 35/35 | 0.875 | 0.640 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 11.722 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 3.296 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.094 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117 | 2.609 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 2.601 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363 | 2.521 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 2.444 |
| `Analyzer\InertiaEntryReferencesHook::analyze` | 21039 | 2.368 |

## Correctness and limits

All 52 targeted suites passed on native Linux with their original deadlines. The new 50-control original/new aggregate scan oracle covers genuine uppercase/nested getters, static/nullsafe/dynamic dispatches, first-class callables, malformed source, batches, duplicate spans, actual call-cap failure, active-only registration and mutable-return isolation. Existing shared-outline, XML and eight-family source-cohort oracles pass. The untimed complete corpus compares 6380 whole original/new scanner states on 580 PHP inputs, with 8700 isolated/batched policy comparisons and input checks for all fifteen active projections, plus independent original declaration-index and typed-dispatch checks. Separate native aggregate suites include integrated, changed-body and collision scenarios; other affected native families and one/three-worker collection integration pass with original heaps and deadlines. Seven controller protocol checks pass. All sixteen full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset.

Six-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Separate global cache profiles

Two minimal instrumented source profiles are excluded from ordinary timings. They add counters and inclusive collector timers to shared syntax methods; SDK dispatch remains uninstrumented. Collector elapsed times are not CPU savings and nested query/parse timers are not additive. GC mode stays enabled.

| Variant | AST calls | Hits | Misses | AST evictions | Query evictions | Query peak entries per worker | Query peak encoded MiB per worker |
| --- | ---: | ---: | ---: | ---: | ---: | --- | --- |
| baseline | 17773 | 16431 | 1342 | 0 | 0 | 8061, 7883 | 3.50, 3.94 |
| candidate | 16698 | 15360 | 1338 | 0 | 0 | 8519, 8487 | 7.33, 7.56 |

## Complete-corpus audit

The 580 selected PHP inputs contain 48194 distinct per-file call keys and 16567 property keys. Only 1 files have a broad XML origin and 1 have one of the existing collection origin forms. These totals cover the full 580-file audit, which includes all 531 analyzed-scope PHP files. The original eight scanner families, XML, nonempty-collection and aggregate scanners retain complete old/new state; typed dispatch fields match an independent fresh-AST oracle. Original declaration-index fields remain exact; rootless files still reserve all unsupported call/property spans.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds after excluded warmups. Fresh scopes, pinned executables, source seals and read-only dependencies use the same protocol. Successful PHPStan caches are verified and removed outside timing. All slow observations remain included.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current aggregate snapshot candidate | 3 | 47.66 (46.27-52.94) | 84.52 (81.64-89.16) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 41.65 (39.55-41.71) | 76.78 (73.74-76.84) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 41.18 (40.77-41.96) | 76.59 (75.00-77.46) | 8373 |

Rule sets, source annotations and ignores differ. Counts do not establish equal depth; ratios from different timing studies are not multiplied.

## Repeated depth and delivery checks

The 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control and Mago with/without Laramago: 924 marked observations, 23 threshold oracles. Fixture/expectation hashes and selected findings match the previous native matrix. Entire production inventories, worker, presets and Composer runtime settings match the tested overlay/release contracts. All 584 selected original and copied input hashes match the initial snapshot. Full Composer checks and hosted CI remain unrun.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-15.json).
