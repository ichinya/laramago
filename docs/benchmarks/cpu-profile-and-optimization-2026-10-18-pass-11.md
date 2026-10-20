# Eight shared source-scan compilers

Baseline is the exact [accepted twenty-six-class candidate](cpu-profile-and-optimization-2026-10-18-pass-10.md). Eight additional scan compilers project only syntax facts from the existing owned read-only batch tree: ValidatedYieldedLists, DefensiveArrayGuardProofs, ReconstructedArrayShapes, FiniteMapArgumentProofs, ValidatedIntegerCalls, ModelRefreshCalls, KernelIntersectionCalls and AssertedPureGetterCalls. Actual owner initialization registers nine policies; FiniteMap keeps symbol admission separate from proof extraction so duplicate failures defer later work. Paths, hashes, lifecycle, generation, limits, cancellation and native decisions remain with consumers. Four call indexes retain all noncandidate span reservations and compact per-file duplicates without changing first-occurrence order or ambiguity. Their bounded prefix has at most 100001 unique keys and must trigger the unchanged 100000-key consumer bound. The temporary owned batch tree is released before decoding the escaping result. Other query/AST budgets, worker/preset configuration and all native/freshness checks remain unchanged. The complete release overlay has thirty-nine classes.

## Paired ordinary timings

Four alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 60.75 (60.32-61.89) | 110.26 (109.69-112.68) | 2799.47 |
| candidate | 4 | 54.33 (53.27-54.77) | 97.67 (95.94-98.62) | 2817.31 |

Observed reductions: 10.57% elapsed, 11.42% CPU. Scope peak median change: +17.83 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 60.90 | 54.55 | 6.71 | 7.24 |
| 2 | candidate / baseline | 60.60 | 53.27 | 7.25 | 7.33 |
| 3 | baseline / candidate | 61.89 | 54.77 | 6.95 | 7.39 |
| 4 | candidate / baseline | 60.32 | 54.11 | 6.93 | 7.23 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2/2 | 2.456 | 11.431 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4/4 | 5.129 | 1.598 |
| `Analyzer\StaticAnalysis\DefensiveArrayGuardProofs::scan` | 2/2 | 2.806 | 0.013 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 2.786 | 0.022 |
| `Analyzer\StaticAnalysis\ValidatedIntegerCalls::scan` | 2/2 | 2.337 | 0.027 |
| `Analyzer\StaticAnalysis\ValidatedYieldedLists::scan` | 2/2 | 2.002 | 0.012 |
| `Analyzer\StaticAnalysis\ReconstructedArrayShapes::scan` | 2/2 | 1.867 | 0.015 |
| `Analyzer\StaticAnalysis\KernelIntersectionCalls::scan` | 2/2 | 1.870 | 0.027 |
| `Analyzer\StaticAnalysis\ModelRefreshCalls::scan` | 2/2 | 1.616 | 0.029 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 4.320 | 3.146 |
| `Analyzer\MixManifestParseHook::analyze` | 7938/7938 | 2.109 | 2.996 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 2.655 | 3.465 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2 | 11.431 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.446 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.055 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.843 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117 | 3.465 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.146 |
| `Analyzer\MixManifestParseHook::analyze` | 7938 | 2.996 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 2.728 |

## Correctness and limits

All 45 targeted suites passed on native Linux with their original deadlines. The new 844-control suite binds preserved original scan bodies to independent scanner instances and compares full serialized state and exception outcomes, using unchanged private syntax predicates. Every scanner has a genuine positive source witness. Controls cover batches, repeated paths/spans, declaration duplicates, ticks, malformed source, initialization, cancellation and actual failure at the existing call cap. These are syntax/scan-state checks, not native proof acceptance. Separate genuine native suites cover the eight affected rule families and their unsafe residual diagnostics. A further untimed real-corpus audit compares 4640 full original/new scan states and performs 8120 input-immutability checks plus 8120 exact isolated/batched result comparisons on 580 selected PHP files, including all 531 analyzed-scope PHP files. Seven controller protocol checks pass. All twelve full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Four-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current thirty-nine-class candidate | 3 | 57.14 (53.81-59.76) | 102.83 (97.75-108.19) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 41.00 (40.89-42.85) | 76.14 (76.04-79.35) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 43.80 (41.41-58.90) | 77.58 (76.80-91.14) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Shared syntax cache profiles and full-corpus audit

These additional profiles cover all shared AST/query calls and use precise requested-method boundaries. They are excluded from ordinary speed samples. Nested CPU sections are not additive. The audit is also untimed; its 580 selected PHP inputs include 531 PHP files under the measured analysis roots. Aggregate serialized result sizes describe this finite corpus, not a universal memory requirement.

| Variant | AST calls | Hits | Misses | Evictions | AST peak per worker, MiB | Query peak per worker, MiB | Query peak entries per worker | Query evictions |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | ---: |
| baseline | 27328 | 25984 | 1344 | 0 | 153.61, 151.85 | 2.06, 1.74 | 3224, 3162 | 0 |
| candidate | 19856 | 18519 | 1337 | 0 | 154.01, 147.37 | 5.79, 5.82 | 7964, 7979 | 0 |

The initial new unit run rejected an insufficient positive mapper fixture: it lacked the existing mandatory string parameter and named return type. The fixture was corrected without changing production predicates. That stopped gate set is retained separately and contributed zero ordinary timing samples. The final gate set contains all 45 passing suites.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-11.json).
