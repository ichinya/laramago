# XML scope origin selector

Baseline is the exact [accepted thirty-nine-class pass-11 candidate](cpu-profile-and-optimization-2026-10-18-pass-11.md); the rejected early-registration experiment remains reverted. The sole new production edit is SimpleXmlProvenance. Its existing unsafe-node walk now also records whether a scope contains either existing XML root form: a resolved SimpleXMLElement constructor or a simplexml_load_string call. A scope with no such origin cannot populate the initially empty tracked-XML state, so analysis returns null before building parent links and further scope indexes. The selector is deliberately broader than root admission: argument/options checks, flow analysis and native contracts are unchanged. No extra traversal or query policy is added. All unrelated, dynamic and nullsafe property-span reservations, duplicate ambiguity, limits, cancellation, file identity and source freshness remain unchanged. GC mode and cache budgets are unchanged. The complete release overlay has forty classes.

## Paired ordinary timings

Six alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 6 | 55.10 (53.94-57.99) | 98.70 (96.73-104.42) | 2826.16 |
| candidate | 6 | 52.74 (50.76-54.08) | 94.74 (91.00-97.31) | 2818.61 |

Observed reductions: 4.28% elapsed, 4.01% CPU. Scope peak median change: -7.55 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 54.45 | 54.08 | 7.87 | 7.89 |
| 2 | candidate / baseline | 55.76 | 51.31 | 7.60 | 7.67 |
| 3 | baseline / candidate | 54.00 | 53.54 | 7.93 | 8.79 |
| 4 | candidate / baseline | 57.99 | 52.59 | 8.02 | 7.85 |
| 5 | baseline / candidate | 56.21 | 52.89 | 7.94 | 7.79 |
| 6 | candidate / baseline | 53.94 | 50.76 | 8.22 | 8.64 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 4.025 | 1.934 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 3.613 | 2.163 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 2.337 | 1.668 |
| `Analyzer\CapturedArrayWarningFilter::filterIssue` | 23/23 | 0.892 | 0.253 |
| `Analyzer\ForceFillWriteContractHook::analyze` | 117/117 | 2.770 | 2.161 |
| `Analyzer\CollectionHelperProvider::getReturnType` | 42/42 | 0.465 | 0.071 |
| `Analyzer\LiteralForeachSelectionReturnFilter::filterIssue` | 70/70 | 0.459 | 0.222 |
| `Analyzer\WeakStringableReturnFilter::filterIssue` | 55/55 | 1.019 | 0.789 |
| `Analyzer\ValidatedForeachReturnFilter::filterIssue` | 14/14 | 0.265 | 0.036 |
| `Analyzer\RequiredFileReferencesHook::analyze` | 46/46 | 0.182 | 0.394 |
| `Analyzer\StaticAnalysis\NonEmptyCollectionCalls::scan` | 2/2 | 2.177 | 1.966 |
| `Analyzer\GuardedLocalModelTupleArgumentFilter::filterIssue` | 2030/2030 | 0.826 | 0.624 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\StaticAnalysis\AssertedPureGetterCalls::scan` | 2 | 11.540 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.755 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 3.714 |
| `Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::scan` | 2 | 3.097 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 2.863 |
| `Analyzer\InertiaEntryReferencesHook::analyze` | 21039 | 2.844 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 2.630 |
| `Analyzer\GuardedNullFlowIssueFilter::filterIssue` | 1272 | 2.203 |

## Correctness and limits

All 49 targeted suites passed on native Linux with their original deadlines. The new 217-control suite compares complete scanner state and exception outcomes against preserved original scan and analyse bodies from tag 0.1.6, using unchanged private predicates. Positive constructor/parser, namespace/alias/case controls, opaque and dynamic sources, malformed and oversized input, batches, duplicate paths, cancellation, reset and a genuine unrelated-property span collision are covered. This is a scan-state oracle, separate from native proof acceptance. Native XML provenance, XML properties and cardinality gates passed, including 69 provenance source cases and integrated execution. An untimed real-corpus audit compares complete old/new state on all 580 selected PHP files, including all 531 analyzed-scope files. Seven controller protocol checks pass. All sixteen full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset.

Six-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Minimal scan and GC profiles

These are separate instrumented observations and are excluded from the six ordinary timing pairs. Only the single pinned SDK scan dispatch is wrapped; other SDK dispatches are unchanged. Small shared-cache counters and inclusive collector timers are also present. Collector time is reported elapsed time, not CPU time or a predicted saving. Timers describe where collection ran, not which handler originally created every collected value. Nested query/parse timers are not additive. Worker GC mode remains enabled and is never changed.

| Variant | Total worker collector time, s | XML scan collector time, s | Values collected during XML scan | AST calls | Query evictions | Query peak entries per worker |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| baseline | 9.001 | 0.789 | 1103140 | 19855 | 0 | 7960, 7985 |
| candidate | 6.550 | 0.000 | 0 | 19881 | 0 | 7958, 7987 |

The preceding low-overhead cache-only diagnosis showed zero collector time inside the owned source projection batch and no query/AST evictions. This ruled out changing GC mode around that batch as the next evidence-backed action. A separate two-run scan diagnosis identified 551570 values collected in each worker while SimpleXmlProvenance scanned; the implementation therefore avoids constructing unnecessary parent-linked scope graphs.

## Real-corpus state audit

All 580 selected PHP files match complete original/new scanner state. The census contains 4137 top-level function/method scopes: 1 contain a broad XML origin and 4136 do not. The corpus contains 531 PHP files under the actual benchmark analysis roots. These census totals apply to the 580-file audit, not only the analysis roots.

## Fresh rotating comparison with Larastan

Each profile occupies each position once across three rounds after excluded warmups, using the same quotas, pinned executables, source seals and read-only dependencies. Successful PHPStan cache cleanup happens after verification and outside the timed command.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current XML-origin candidate | 3 | 52.35 (51.81-53.64) | 93.74 (92.83-96.02) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 43.01 (41.39-44.46) | 80.03 (76.66-82.44) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 44.44 (43.33-45.38) | 82.10 (80.47-84.37) | 8373 |

Rule sets, source annotations and configuration ignores differ; diagnostic counts do not establish equal analysis depth. All slow observations remain included. Ratios from different studies are not multiplied.

## Repeated depth and delivery checks

The 33 defect/control pairs again pass across PHPStan levels 0-10, a separate default-config-types level-10 control, and Mago with/without Laramago: 924 marked observations and 23 threshold oracles. Fixture/expectation hashes and every selected finding match the previous matrix. The whole current production source inventory matches the tested frozen overlay. Worker, presets and Composer runtime configuration match the installed release. All 584 selected original and copied workload inputs still match the initial snapshot. Full Composer checks and hosted CI remain unrun.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-13.json).
