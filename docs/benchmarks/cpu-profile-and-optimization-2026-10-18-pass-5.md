# Shared syntax and conservative reference scanning

Baseline is the exact [accepted eighteen-class candidate](cpu-profile-and-optimization-2026-10-18-pass-4.md). Two additional changes remove repeated syntax work. Coalesce model-property checks reuse the bounded exact-byte AST cache after their existing fresh physical reads. Stored reference scans use ordinary shared resolved syntax when source contains no ampersand, because the recognized proof requires three literal closures capturing by reference. Full parsing, name resolution, every declaration index, duplicate rejection, lifecycle and cancellation checks remain. Ampersand-positive inputs and every late declaration hydration retain the original PHPDoc alias resolver. No native metadata or final diagnostic decision is cached.

## Paired ordinary timings

Two alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 2 | 81.36 (78.52-84.21) | 151.00 (145.42-156.58) | 2860.03 |
| candidate | 2 | 74.28 (72.01-76.56) | 137.83 (133.39-142.27) | 2863.10 |

Observed reductions: 8.70% elapsed, 8.72% CPU. Scope peak median change: +3.07 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 78.52 | 72.01 | 7.02 | 6.61 |
| 2 | candidate / baseline | 84.21 | 76.56 | 6.47 | 6.38 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905/1905 | 6.527 | 3.023 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 8.132 | 6.830 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 3.297 | 2.552 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 8.655 | 9.323 |
| `Analyzer\StaticAnalysis\ValidatedIntegerCalls::scan` | 2/2 | 2.263 | 2.925 |
| `Analyzer\StaticAnalysis\ArrayAssertionCalls::scan` | 2/2 | 2.306 | 2.892 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 3.802 | 4.387 |
| `Analyzer\StaticAnalysis\ReconstructedArrayShapes::scan` | 2/2 | 1.827 | 2.395 |
| `Analyzer\StaticAnalysis\NonEmptyCollectionCalls::scan` | 2/2 | 2.186 | 2.675 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 2.547 | 3.034 |
| `Analyzer\WeakStringableReturnFilter::filterIssue` | 55/55 | 1.520 | 1.046 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2/2 | 3.007 | 2.540 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 9.323 |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 8.573 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 6.830 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.387 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.138 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.998 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.997 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263 | 3.854 |

## Correctness and limits

All 12 targeted suites passed on native Linux with their original deadlines. Thirty-five new controls cover independent ASTs, analyzed-byte equality, current physical identity, same-size/same-mtime mutations, invalid and oversized input, restoration, deletion, declaration and PHPDoc alias hydration, duplicate names and paths, batch completion, generation reset and cancellation. Marker-negative scan state is compared to the unchanged reference parser forced by an inert same-length comment. Native coalesce, stored-callback and direct-reference matrices verify positive witnesses, unsafe residual diagnostics, source/metadata changes and integrated one/three-worker parity. Shared syntax consumers, budget controls and factory binding oracles also pass. Seven controller protocol checks pass. All eight full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Two-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current twenty-class candidate | 3 | 84.22 (75.47-84.67) | 154.97 (139.29-157.24) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 47.06 (46.52-51.81) | 87.89 (87.33-95.78) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 53.07 (43.85-53.12) | 98.52 (81.55-98.64) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Additional source-reader profile

A separate instrumented full-workload probe retains all 13,100 diagnostics and is excluded from ordinary timings. Counters measure the existing per-reader and shared path/hash caches. Encoded-byte totals below are cumulative throughput, not resident memory. Nested CPU sections are in JSON and must not be summed.

| Source-reader event | Calls | Cumulative encoded MiB |
| --- | ---: | ---: |
| calls | 7821 | 0.00 |
| instance-miss | 4702 | 0.00 |
| shared-miss | 1938 | 0.00 |
| serialized-on-miss | 1938 | 298.39 |
| instance-hit | 3119 | 0.00 |
| shared-eviction | 1912 | 283.84 |
| shared-hit | 2764 | 0.00 |

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-5.json).
