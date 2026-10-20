# Bounded per-proof syntax reuse

Baseline is the exact [accepted twenty-class candidate](cpu-profile-and-optimization-2026-10-18-pass-5.md). The only further production change is NullableCollectionOffsetContract. Its private read-only AST consumers reuse a bounded syntax window within one current() proof. Each source access still reads and hashes physical bytes; path identity, source-size limits, native metadata queries and post-RPC freshness checks remain unchanged. A WeakMap isolates concurrent Fibers, nested proofs restore their outer window, and finally blocks release completed or failed windows. At most eight trees with eight MiB of cumulative encoded weight are retained per active proof; expanded object memory can be larger and scope peaks are measured below. Standalone reads and the public shared parser retain independent mutable ASTs. No native metadata or final diagnostic decision is cached.

## Paired ordinary timings

Two alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 2 | 76.48 (75.05-77.91) | 140.24 (137.92-142.57) | 2847.74 |
| candidate | 2 | 74.43 (72.29-76.58) | 136.87 (132.99-140.76) | 2851.95 |

Observed reductions: 2.67% elapsed, 2.40% CPU. Scope peak median change: +4.22 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 77.91 | 76.58 | 7.68 | 7.20 |
| 2 | candidate / baseline | 75.05 | 72.29 | 7.27 | 6.80 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 6.927 | 1.804 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 7.264 | 5.612 |
| `Analyzer\CollectionOffsetGuardFilter::filterIssue` | 35/35 | 1.994 | 0.922 |
| `Analyzer\MixManifestParseHook::analyze` | 7938/7938 | 2.001 | 3.010 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2/2 | 3.474 | 2.479 |
| `Analyzer\StaticAnalysis\ContextualCollectionMembers::scan` | 2/2 | 2.001 | 2.805 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263/263 | 3.774 | 3.054 |
| `Analyzer\StaticAnalysis\DefensiveArrayGuardProofs::scan` | 2/2 | 2.509 | 3.198 |
| `Analyzer\StaticAnalysis\ValidatedYieldedLists::scan` | 2/2 | 2.200 | 1.579 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 4.305 | 3.724 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 2.270 | 2.784 |
| `Analyzer\RememberedGuardedGetterScan::scan` | 2/2 | 1.813 | 2.196 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 8.196 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 5.612 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.042 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.998 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4 | 3.827 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 3.724 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363 | 3.484 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4 | 3.456 |

## Private source-window observations

These are nested sections from the excluded callback profiles. Source-access counts include the existing physical-read guard; the source reader still reads current disk bytes before any private window lookup. Shared-parser counters cover only the fallback branch.

| Variant | Source-access calls | Shared-AST fallback calls | Source section CPU, s |
| --- | ---: | ---: | ---: |
| baseline | 3486 | 3486 | 5.212 |
| candidate | 3486 | 108 | 0.328 |

## Correctness and limits

All 16 targeted suites passed on native Linux with their original deadlines. Twenty-six new controls cover private syntax reuse with fresh same-size/same-mtime disk bytes, post-metadata vetoes, deletion, malformed and oversized input, exact restoration, nested proof isolation, exceptions, out-of-order concurrent Fibers, abandoned Fibers, entry eviction and large trees that must not be retained. The unchanged standalone-reader controls preserve independent mutable ASTs. Native nullable collection, collection-offset guard and framework null-flow suites retain their positive witnesses, unsafe residual diagnostics, source/metadata mutations and full-registry worker parity. Source-cache and callback controls included in these sixteen targeted suites also pass. Seven controller protocol checks pass. All eight full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Two-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current twenty-class candidate | 3 | 74.70 (70.20-75.37) | 137.45 (128.72-138.15) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 42.02 (41.48-47.31) | 78.33 (77.58-86.62) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 43.85 (42.06-45.08) | 79.72 (78.34-81.35) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Additional source-reader counters and ownership audit

A separate instrumented full-workload probe retains all 13,100 diagnostics. Its 3486 AST digest checks at hits, pre-eviction and proof exit verify unchanged private syntax across 11 completed proof windows. Additional audit serialization makes this probe unsuitable for callback speed comparisons; it is excluded from all ordinary timings. Counters measure the existing per-reader and shared path/hash caches. Encoded-byte totals below are cumulative throughput, not resident memory. Nested CPU sections are in JSON and must not be summed.

| Source-reader event | Calls | Cumulative encoded MiB |
| --- | ---: | ---: |
| calls | 7838 | 0.00 |
| instance-miss | 4701 | 0.00 |
| shared-miss | 1977 | 0.00 |
| serialized-on-miss | 1977 | 318.65 |
| instance-hit | 3137 | 0.00 |
| shared-eviction | 1935 | 303.62 |
| shared-hit | 2724 | 0.00 |

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-6.json).
