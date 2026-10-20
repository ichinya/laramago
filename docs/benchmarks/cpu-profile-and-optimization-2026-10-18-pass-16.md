# Fused Faker registration walks and necessary assertion selection

Baseline is the exact [accepted forty-three-class candidate](cpu-profile-and-optimization-2026-10-18-pass-15.md). Two production modules change; the complete release overlay has forty-five classes. FactoryFakerRegistrationReceivers collects five source-node groups in one read-only traversal, then consumes each in its original preorder and catalogue-update order. Existing nested-class trait alias visits and physical facade checks remain. DirectCallbackReferenceEffects combines declaration/tick/necessary-assertion selection in one walk. Without the exact resolved Testo Assert true/false call form, its existing candidates method cannot produce a proof, so redundant method-body checks are skipped. Every class declaration remains in the source-bound catalogue for later native/current-source validation. Native metadata, physical/source freshness, cancellation, bounds, collision handling, rules, worker settings and GC mode stay unchanged. No additional cached projection or cache-budget increase is introduced.

## Paired ordinary timings

Six alternating pairs, fresh two-CPU/six-GiB scopes; PHP 8.5.4, Mago 1.48.1, two analyzer threads/workers, 2-GiB PHP heaps, OPcache/JIT off and read-only dependencies. Profiles and warmups are excluded. Slow samples are retained. All observations verify the existing complete diagnostic seal.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 6 | 231.10 (139.62-474.43) | 208.33 (132.95-408.42) | 2840.32 |
| candidate | 6 | 200.04 (118.55-325.36) | 182.53 (110.22-294.83) | 2845.61 |

Observed reductions: 13.44% elapsed and 12.38% CPU. Improved pairs: 4/6 elapsed and 4/6 CPU. Scope peak median change: +5.28 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline CPU PSI some, % | Candidate CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 314.87 | 288.30 | 65.91 | 64.32 |
| 2 | candidate / baseline | 474.43 | 325.36 | 67.70 | 64.52 |
| 3 | baseline / candidate | 456.85 | 238.14 | 69.49 | 62.16 |
| 4 | candidate / baseline | 147.33 | 118.55 | 56.21 | 56.15 |
| 5 | baseline / candidate | 144.82 | 161.94 | 58.18 | 58.11 |
| 6 | candidate / baseline | 139.62 | 160.49 | 55.45 | 57.49 |

## Descriptive paired variability

Changes below are computed within each complete pair; negative values favor the candidate. The resampling interval describes these six observed pairs and cannot remove changing VM load, CPU frequency or temporal dependence. Primary median comparisons remain unchanged; no observations are trimmed.

| Metric | Mean paired change, % | Median paired change, % | Central 95% resampling interval for mean, % |
| --- | ---: | ---: | --- |
| wall_seconds | -13.42 | -13.99 | -31.09 to +4.26 |
| cpu_seconds | -13.43 | -14.96 | -29.33 to +2.47 |

Runtime verdict: inconclusive on this shared VM. Both descriptive intervals include no improvement, four of six pairs improve and the last two pairs regress. The median reductions above are observed point estimates, not an established causal speedup. This is a conservative post-observation interpretation, not a predeclared significance test. Correctness is accepted; runtime acceptance remains pending replication.

## Separate handler CPU profiles

All fourteen pinned SDK dispatch sites are instrumented; both workers are terminal with zero active calls. Profiles are excluded from ordinary timings. Nested/inclusive sections are not added. The common fifteen-policy extraction batch still belongs to its first requesting scan callback; that owner row includes work for the whole cohort.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\FactoryFakerContracts::scan` | 2/2 | 7.461 | 5.941 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 5.092 | 3.225 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2/2 | 19.547 | 44.716 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 3.926 | 4.204 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2/2 | 3.785 | 3.713 |
| `Analyzer\InertiaEntryReferencesHook::analyze` | 21039/21039 | 4.412 | 3.695 |
| `Analyzer\RememberedGuardedGetterScan::scan` | 2/2 | 2.586 | 3.604 |
| `Analyzer\StaticAnalysis\ArrayAssertionCalls::scan` | 2/2 | 3.503 | 3.482 |
| `Analyzer\MixManifestParseHook::analyze` | 7938/7938 | 3.887 | 3.314 |

The separate profile collector timers are reported as elapsed sums across two workers, not as CPU savings or a critical-path duration. GC mode is unchanged.

| Variant | PHP worker CPU sum, s | Collector elapsed sum, s | Collector runs |
| --- | ---: | ---: | ---: |
| baseline | 184.97 | 35.53 | 53 |
| candidate | 139.60 | 17.22 | 61 |

## Correctness and package isolation

All 55 targeted native suites pass at their original heaps and deadlines, including the complete default Faker contract scenario and one/three-worker callback integration. New original/new oracles cover 2112 Faker catalogue-state/input checks and 200 direct-reference state/exception checks. The complete selected corpus audit compares 1160 isolated/cumulative Faker states and 580 full callback scanner states on 580 PHP files, including all 531 analyzed-scope files. AST input and accumulated update order remain unchanged. All sixteen main-study observations preserve all fields and duplicates of all 13100 diagnostic records.

Files with relative includes now use complete private src/bin/presets package snapshots, with all namespace declarations mapped consistently. PHP source constants are not rewritten. The entire candidate source inventory matches the native correctness package; newly added, removed or changed package files invalidate measurements. Eight native observations on unchanged production code verified parity before optimization. Twelve controller protocol tests pass, including relative peers, assets, unexpected paths and reuse of an existing diagnostic seal without replacing it.

## Fresh rotating comparison with Larastan

Three rounds after excluded warmups, with each configuration occupying each position once. Successful PHPStan caches are verified and removed outside timing.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current candidate | 3 | 100.89 (100.28-103.98) | 94.88 (93.84-99.53) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 93.65 (90.37-102.41) | 91.42 (87.72-98.60) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 97.46 (93.01-98.80) | 94.75 (91.43-96.29) | 8373 |

Rule sets, source annotations and ignores differ; counts do not establish equal depth. Changing shared-VM load and uncontrolled CPU frequency limit local timing conclusions. Historical ratios from different studies are not multiplied.

## Repeated depth and delivery checks

All 33 defect/control pairs retain the same selected findings across PHPStan levels 0-10, the separate default-config-types level-10 control and Mago with/without the extension: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes match pass 15. All 584 selected original/copied inputs remain unchanged. Worker, presets and Composer runtime configuration are unchanged. Full Composer checks and hosted CI remain unrun. Changes remain local and uncommitted.
Before this complete series, 168 generated caches from fourteen successfully verified older depth matrices were removed from tmpfs, retaining source fixtures and reports. An earlier executor interruption, a separate SDK timeout under severe memory/swap pressure, and a profile collector failure on a valid empty worker section list produced no ordinary timing observations and are excluded. This fourth series starts after the resource repair and collector fix. All twelve newly generated PHPStan depth caches are verified and removed only after the complete depth oracle passes. Native/SDK deadlines are unchanged.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-16.json).
