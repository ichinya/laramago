# Early factory mapping registration

**Experiment rejected and reverted.** Both timing series have slower candidate medians. The working production sources are restored to the accepted thirty-nine-class pass-11 candidate; frozen experimental snapshots remain available.

Baseline is the exact [accepted thirty-nine-class candidate](cpu-profile-and-optimization-2026-10-18-pass-11.md). During the experiment, FactoryResultContract exposed stateless mapping projection registration. Its two actual scanning owners, RefreshedModelProperties and LiteralForeachSelectionReturnFilter, registered the two policies during initialization, before the first source batch. Standalone scan registration remained as an idempotent fallback. Inactive owner construction registered nothing. The fourteen existing policies could therefore share the first owned resolved tree rather than entering a second batch when factory scanning starts. Mapping extraction, result payloads, budgets, native/freshness checks, lifecycle and diagnostics are unchanged. The experimental overlay had forty classes and changed three production files. Those three changes were subsequently reverted; see the restoration receipt below.

## Paired ordinary timings

Four alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 55.38 (54.12-56.20) | 100.02 (97.57-101.27) | 2827.22 |
| candidate | 4 | 58.56 (53.40-72.72) | 105.61 (96.22-111.73) | 2828.82 |

Observed reductions: -5.73% elapsed, -5.59% CPU. Scope peak median change: +1.60 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 54.97 | 53.40 | 7.45 | 7.62 |
| 2 | candidate / baseline | 54.12 | 55.30 | 7.90 | 7.52 |
| 3 | baseline / candidate | 55.79 | 61.81 | 7.72 | 8.06 |
| 4 | candidate / baseline | 56.20 | 72.72 | 7.54 | 24.68 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between handlers; profile changes describe this observation. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4/4 | 1.551 | 0.078 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2/2 | 4.540 | 3.073 |
| `Analyzer\OrdinaryMixedAssignmentFilter::filterIssue` | 2356/2356 | 2.461 | 1.691 |
| `Analyzer\StaticAnalysis\NonEmptyCollectionCalls::scan` | 2/2 | 2.081 | 2.685 |
| `Analyzer\ScalarOperandCompatibilityFilter::filterIssue` | 463/463 | 0.931 | 1.477 |
| `Analyzer\StaticAnalysis\ConditionalArrayGuards::scan` | 2/2 | 1.128 | 1.651 |
| `Analyzer\StringPredicateArrayProvider::getReturnType` | 56/56 | 0.862 | 1.309 |
| `Analyzer\WeakStringableReturnFilter::filterIssue` | 55/55 | 1.098 | 0.686 |
| `Analyzer\GuardedNullFlowIssueFilter::filterIssue` | 1272/1272 | 2.716 | 2.313 |
| `Analyzer\ConfigurationFacadeProvider::getReturnType` | 29/29 | 0.009 | 0.324 |
| `Analyzer\ConfigurationProvider::getReturnType` | 61/61 | 0.317 | 0.003 |
| `Analyzer\ValidatedArrayContractPlugin::filterIssue` | 1020/1020 | 1.120 | 0.815 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2 | 12.822 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.020 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 3.568 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.318 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363 | 3.097 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.073 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 2.825 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 2.696 |

## Correctness and limits

All 48 targeted suites passed on native Linux with their original deadlines. The new 113-control registration suite checks both actual active owners, absence of registration on inactive construction, stateless callbacks, exact-byte first-batch prefill, genuine positive and negative mapping witnesses, complete standalone/early factory scan-state equality, fallback idempotence and generation reset. The prior 844 original/new scanner-state controls also pass. Separate genuine native suites cover affected factory, refreshed-property and literal-foreach families, including retained unsafe diagnostics. The untimed real-corpus audit repeats 4640 full original/new scan-state comparisons, 8120 input-immutability checks and 8120 exact isolated/batched result comparisons on 580 selected PHP files, including all 531 analyzed-scope files. These syntax audits are separate from native proof acceptance. Seven controller protocol checks pass. All twelve full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. At experimental validation, the whole PHP-source inventory matched the frozen forty-class overlay. Other production sources, workers, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs match the initial snapshot.

Four-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. The candidate is preserved in the frozen local experiment; its production changes were reverted.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds, after excluded warmups. Fresh scopes, source seals, executable hashes and read-only dependencies use the same protocol as the paired study. Successful PHPStan caches are verified and removed outside the timed interval. Private failure-only analyzer stream capture is separately protocol-verified; the original timed command remains unchanged.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Experimental forty-class candidate | 3 | 55.20 (54.08-58.01) | 98.94 (97.07-104.46) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 45.52 (41.13-47.31) | 83.68 (76.70-84.34) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 43.76 (43.13-46.13) | 80.98 (80.08-85.29) | 8373 |

Different rule sets, configuration ignores and source annotations prevent assigning a universal speed ranking or equivalent depth from these counts. Pressure snapshots and timestamps are in JSON. Ratios from independent series are not multiplied.

## Repeated public depth matrix

The same 33 defect/control pairs pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture and expectation hashes and every selected finding match the previous native matrix. This finite invented fixture does not assign Laramago a universal PHPStan level. Full Composer checks and hosted CI remain unrun.

## Shared syntax cache profiles and full-corpus audit

These additional profiles cover all shared AST/query calls and use precise requested-method boundaries. They are excluded from ordinary speed samples. Nested CPU sections are not additive. The audit is also untimed; its 580 selected PHP inputs include 531 PHP files under the measured analysis roots. Aggregate serialized result sizes describe this finite corpus, not a universal memory requirement.

| Variant | AST calls | Hits | Misses | Evictions | AST peak per worker, MiB | Query peak per worker, MiB | Query peak entries per worker | Query evictions |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | ---: |
| baseline | 19845 | 18504 | 1341 | 0 | 148.85, 154.22 | 5.75, 5.86 | 7947, 7996 | 0 |
| candidate | 18804 | 17461 | 1343 | 0 | 153.09, 151.96 | 6.07, 5.55 | 7960, 7984 | 0 |

Both new active-owner paths and the standalone fallback passed; all 48 native gate suites completed. Cache counter differences include worker scheduling; ordinary paired timing, rather than moving callback attribution, measures the speed change.

## Fixed six-pair replication

End-to-end speedup is not confirmed: the first four-pair series regressed despite eliminating the second source batch. A fixed six-pair replication is reported separately, with all original observations retained. Structural work reduction alone is not a speed acceptance gate.

The repeat uses exactly the same production sources, baseline, executables, quotas and timing controller. Profiles and warmups are excluded. Both complete series remain separate; the repeat does not replace the first series.

| Variant | N | Elapsed median (range), s | CPU median (range), s |
| --- | ---: | ---: | ---: |
| baseline | 6 | 55.44 (53.54-56.22) | 100.11 (96.26-100.57) |
| candidate | 6 | 55.98 (53.03-61.33) | 100.40 (95.67-109.40) |

| Round | Order | Baseline elapsed / CPU, s | Candidate elapsed / CPU, s | Baseline CPU PSI some, % | Candidate CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 55.78 / 100.57 | 54.96 / 97.92 | 7.43 | 7.52 |
| 2 | candidate / baseline | 56.22 / 100.30 | 55.61 / 99.95 | 9.18 | 7.70 |
| 3 | baseline / candidate | 53.54 / 96.26 | 61.33 / 109.40 | 7.63 | 9.82 |
| 4 | candidate / baseline | 53.99 / 97.13 | 53.03 / 95.67 | 8.04 | 7.35 |
| 5 | baseline / candidate | 55.58 / 100.20 | 56.36 / 101.70 | 7.79 | 7.68 |
| 6 | candidate / baseline | 55.31 / 100.02 | 58.33 / 100.84 | 7.78 | 11.61 |

Repeat-only observed median reductions: -0.97% elapsed and -0.29% CPU. This descriptive result does not remove the contrary first-series evidence. All sixteen repeat observations retain every field and duplicate of all 13,100 diagnostics.

No runtime improvement was established. Both completed timing series have slower candidate medians. The registration-only changes and their new test were reverted; the complete Windows and native production inventories match the accepted thirty-nine-class pass-11 candidate. Fourteen fresh baseline workload observations across the two series preserve all 13,100 diagnostics. Entire production inventories and Composer scripts in both working copies match the pre-experiment snapshots. Full Composer checks and hosted CI remain unrun.


## Restoration

Only the three experiment production edits and their new registration test/Composer entry were removed. The test is preserved in the frozen 48-suite gate package. Both working production inventories match the accepted pass-11 gate hashes. All 584 selected original and copied workload inputs remain unchanged. GC scheduling was not changed. Sixteen verified completed PHPStan cache directories were removed outside the timed intervals; failed earlier caches were preserved.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-12.json).
