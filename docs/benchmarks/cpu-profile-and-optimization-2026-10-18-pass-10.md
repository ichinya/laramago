# Compact native method-body tokens

Baseline is the exact [accepted twenty-four-class candidate](cpu-profile-and-optimization-2026-10-18-pass-9.md). Two additional production classes change, yielding twenty-six classes in the release overlay. ModelReflection adds an internal pure method projection keyed by exact source bytes, original method name, both half-open location bounds and extraction policy. It preserves the original first matching method in preorder. Its public methodNode still returns the reader-owned mutable node, and projections from exposed trees honor every caller edit. EloquentAggregateProjectionProvider caches only normalized actual method-body tokens through this projection. It continues to fetch native metadata and check static/public flags, declaring class, native path identity and expected body independently of that cache. Rejected metadata keeps the original source-reader path and warnings. The booted AST loop and ordering of event metadata requests remain unchanged; only native event-body token extraction uses the new projection. No native metadata or final eligibility decision is cached.

## Paired ordinary timings

Four alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 62.46 (61.49-62.99) | 112.31 (112.02-114.89) | 2857.62 |
| candidate | 4 | 60.91 (57.48-63.79) | 110.18 (105.04-114.23) | 2818.19 |

Observed reductions: 2.49% elapsed, 1.89% CPU. Scope peak median change: -39.43 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 61.49 | 57.48 | 7.03 | 6.83 |
| 2 | candidate / baseline | 62.99 | 59.15 | 7.08 | 6.93 |
| 3 | baseline / candidate | 62.69 | 62.66 | 8.12 | 8.30 |
| 4 | candidate / baseline | 62.24 | 63.79 | 7.89 | 7.85 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263/263 | 2.932 | 0.486 |
| `Analyzer\ScalarOperandCompatibilityFilter::filterIssue` | 463/463 | 1.514 | 0.413 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 2.494 | 3.443 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 3.808 | 4.450 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2/2 | 4.419 | 3.883 |
| `Analyzer\ConfigurationProvider::getReturnType` | 61/61 | 0.003 | 0.498 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 2.813 | 2.352 |
| `Analyzer\DeprecatedMethodCompatibilityProof::scan` | 2/2 | 1.410 | 1.780 |
| `Analyzer\ConfigurationFacadeProvider::getReturnType` | 29/29 | 0.375 | 0.009 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 2.212 | 1.866 |
| `Analyzer\StaticAnalysis\ContextualCollectionMembers::scan` | 2/2 | 2.094 | 1.763 |
| `Analyzer\StaticAnalysis\DefensiveArrayGuardProofs::scan` | 2/2 | 2.835 | 2.509 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4 | 5.011 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.450 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.279 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.883 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2 | 3.443 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363 | 3.212 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.211 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 2.771 |

## Correctness and limits

All 36 targeted suites passed on native Linux with their original deadlines. The new method-projection suite passes 206 controls against an independent fresh resolved parse and explicit preorder/range selection. It checks original-name case, empty and half-open bounds, nested/trait/interface/enum declarations, policy separation, body-token equivalence with the previous printer/token algorithm, mutable methodNode aliasing, old snapshots versus fresh physical bytes, absent/native locations and parse warnings. These selection-only DTO tests make no native metadata or final-proof claim. Genuine native aggregate suites pass in integrated and isolated modes, including changed-body and ambiguous cross-file spans; unsafe residual diagnostics remain. Existing source-projection, factory, refresh, physical-freshness, callback, nullable, tuple and boundary matrices also pass. Seven controller protocol checks pass. All twelve full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The whole delivered PHP-source inventory matches the frozen overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Four-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current twenty-six-class candidate | 3 | 60.32 (59.87-61.34) | 108.70 (107.77-109.55) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 41.71 (41.02-41.85) | 77.50 (76.23-77.79) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 42.04 (41.61-42.63) | 77.89 (77.03-78.92) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

The final detailed probes correct a legacy section-placement bug: an inline opening brace could place the model-default marker in literal(). Preliminary detailed probes are retained and superseded. Ordinary timing samples and their diagnostic checks are unaffected. Every final requested opening and method boundary is validated before analysis.

## Aggregate native-method CPU sections

Separate full-workload profiles preserve every field and duplicate of all 13,100 diagnostics, source/dependency seals and two terminal workers with zero active calls. These instrumented observations are excluded from ordinary speed samples. Sections below are nested and must not be summed. The candidate has extra body-token/projection/selector instrumentation; small deltas are descriptive.

| Operation within EloquentAggregateProjectionProvider | Calls baseline/candidate | Baseline CPU, s | Candidate CPU, s |
| --- | ---: | ---: | ---: |
| aggregate-provider | 263/263 | 3.869 | 0.645 |
| aggregate-native-body | 184/184 | 3.159 | 0.180 |
| aggregate-write-boot | 92/92 | 0.468 | 0.089 |
| model-method-node | 321/56 | 3.345 | 0.058 |
| model-method-projection | 0/265 | 0.000 | 0.195 |
| model-find-method | 0/68 | 0.000 | 0.029 |
| php-source-read | 375/110 | 2.431 | 0.058 |
| php-source-project | 0/265 | 0.000 | 0.194 |
| aggregate-body-tokens | 0/12 | 0.000 | 0.001 |

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-10.json).
