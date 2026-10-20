# Shared primitive declaration indexes and reference selection

Baseline is the exact [accepted twenty-class candidate](cpu-profile-and-optimization-2026-10-18-pass-6.md). Two additional production classes change. StoredCallbackSourceDeclarations projects primitive class names/kinds/spans once from fully parsed and name-resolved source, sharing only exact-byte pure facts through the bounded query cache. Per-root paths, duplicate detection, scan limits, cancellation and failure state remain outside it. StoredReferencePossibleWrites reuses this analyzed top-level index when closure captures are impossible, avoiding its second expanded AST. Missing or poisoned indexes fall back to full parsing. Possible captures and every late hydration retain the original PHPDoc resolver; fresh native metadata and physical-source checks remain unchanged. A conservative token selector accepts either ampersand token anywhere in a closure use list and retains the custom parser after lexical errors. No native metadata or final proof decision is cached.

## Paired ordinary timings

Four alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 70.60 (67.67-71.08) | 128.89 (124.19-131.01) | 2851.48 |
| candidate | 4 | 68.19 (66.76-71.23) | 124.79 (122.23-130.41) | 2854.39 |

Observed reductions: 3.41% elapsed, 3.18% CPU. Scope peak median change: +2.91 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 71.08 | 71.23 | 6.99 | 7.23 |
| 2 | candidate / baseline | 70.66 | 69.14 | 7.81 | 7.15 |
| 3 | baseline / candidate | 70.54 | 67.25 | 6.94 | 7.17 |
| 4 | candidate / baseline | 67.67 | 66.76 | 6.80 | 7.83 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 2.329 | 4.880 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 4.580 | 3.153 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 3.800 | 5.076 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2/2 | 2.397 | 3.658 |
| `Analyzer\StaticAnalysis\ValidatedYieldedLists::scan` | 2/2 | 1.601 | 2.547 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 3.210 | 2.369 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 5.482 | 4.644 |
| `Analyzer\StaticAnalysis\NonEmptyCollectionCalls::scan` | 2/2 | 2.074 | 2.880 |
| `Analyzer\GuardedNullFlowIssueFilter::filterIssue` | 1272/1272 | 2.917 | 2.284 |
| `Analyzer\StaticAnalysis\ModelRefreshCalls::scan` | 2/2 | 1.595 | 2.175 |
| `Analyzer\FactoryFakerContracts::scan` | 2/2 | 3.723 | 4.184 |
| `Analyzer\StaticAnalysis\ContextualCollectionMembers::scan` | 2/2 | 2.201 | 1.777 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 8.586 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 5.076 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363 | 4.880 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 4.644 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 4.244 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.184 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 3.658 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4 | 3.519 |

## Correctness and limits

All 18 targeted suites passed on native Linux with their original deadlines. The 64 declaration-index controls compare names, kinds and spans against fresh independent resolved ASTs; they cover nested/conditional classes, traits/interfaces/enums, ticks, root/path identity, returned-array mutation, partial batches, failed generations, cancellation, initialization and duplicate names. The selector/state suite passes 89 controls including genuine AST capture witnesses and byte-identical compatibility with the unchanged custom parser path. Source-window controls, shared-query failure/mutation/eviction tests and the native callback/source-metadata matrices preserve unsafe residual diagnostics, exact restoration and integrated worker parity. Seven controller protocol checks pass. All twelve full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Four-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current twenty-one-class candidate | 3 | 70.46 (68.67-70.78) | 129.59 (125.57-129.78) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 43.91 (41.56-44.42) | 81.97 (76.92-82.15) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 43.42 (42.79-45.29) | 80.75 (79.12-84.26) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Selected-input selector census

A separate untimed census of 580 selected PHP inputs (4440822 bytes), verified against the initial source snapshot, finds 209 raw-ampersand positives and 38 possible reference-capture positives. These are selected-input counts, not an assertion about every SDK file or a measurement of each path contribution. Full syntax/name validation and later native/source certificates remain required.

## Completed exploratory selector-only series

An earlier two-pair series tested the lexical selector alone. All eight observations were valid and retained 13,100 records; elapsed medians were 76.38 / 74.09 s and CPU medians 136.74 / 136.19 s. The small CPU difference and changing VM load were inconclusive. The shared declaration projection was then added and this separate four-pair experiment was defined. Samples from different source candidates are not pooled or multiplied.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-7.json).
