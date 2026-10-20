# Native CPU optimization, pass 2

Completed local comparison against tagged Laramago 0.1.6 on the same anonymous case 02. The previous pass is documented [separately](cpu-profile-and-optimization-2026-10-17.md).

## Changes

Two tuple argument filters now reject source sites outside a necessary positional tuple loop before physical reconstruction and native requests. This shortcut can only keep a diagnostic. Null-flow, validated-sort and script-array source extractions use the existing exact-byte query cache (8 MiB, 8192 entries) with independent returned values. Physical freshness, native proofs, bounded refusal behavior and post-request checks remain. The previous nullable parsing candidate is included. Model-property arguments must first satisfy necessary named-typed-formal / last-argument syntax. Faker source profiles use the same bounded pure query cache; native registration and priority checks remain fresh.

531 PHP files, 102,439 lines, 4,292,786 bytes; PHP 8.5.4, Mago 1.48.1; native WSL temporary storage. Every observation uses a fresh two-CPU / six-GiB systemd scope, two threads / two extension workers, a read-only vendor tree, 2-GiB PHP limits, OPcache/JIT off and isolated runtime/schema settings. The original application is unchanged. All nine production overrides are frozen and verified by reflection. Selected source paths/bytes, dependency paths/bytes and executable hashes are checked around observations.

## Uninstrumented timings

Two profiles and two warmups are excluded. Four alternating pairs put each variant first twice.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 282.70 (213.70-328.17) | 498.27 (403.00-527.90) | 2236.12 |
| candidate | 4 | 170.21 (115.71-230.60) | 262.65 (219.99-284.84) | 2237.38 |

Observed median reductions: 39.79% elapsed and 47.29% CPU. Candidate faster in 4/4 pairs. Shared-VM variation remains; inspect pair ranges and pressure alongside medians.

| Round | Order | Baseline, s | Candidate, s | Baseline CPU PSI some, % | Candidate CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 213.70 | 115.71 | 3.22 | 4.32 |
| 2 | candidate / baseline | 283.05 | 187.29 | 10.10 | 50.64 |
| 3 | baseline / candidate | 328.17 | 153.14 | 39.13 | 16.76 |
| 4 | candidate / baseline | 282.35 | 230.60 | 5.86 | 55.00 |

CPU PSI measures time with runnable tasks stalled for CPU, not CPU utilization. Scope and system pressure are retained independently in the JSON. Frequency was not fixed.

## Callback CPU attribution

All pinned 14 SDK dispatch sites are instrumented; terminal snapshots have no active calls. Completed call counts and any differences are retained per handler in the JSON; metadata reentrancy can vary provider calls. Nested nullable sections are not added to parent callback totals. Profiles are not timing samples.

| Handler | Calls (baseline/candidate) | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\PossibleCallbackTupleArgumentFilter::filterIssue` | 2033/2033 | 41.712 | 1.157 |
| `Analyzer\GuardedLocalModelTupleArgumentFilter::filterIssue` | 2030/2030 | 41.497 | 1.188 |
| `Analyzer\ValidatedArrayContractPlugin::filterIssue` | 1020/1020 | 35.329 | 0.955 |
| `Analyzer\GuardedNullFlowIssueFilter::filterIssue` | 1272/1272 | 32.946 | 2.537 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11/11 | 21.441 | 8.648 |
| `Analyzer\ModelPropertyArgumentFilter::filterIssue` | 2029/2029 | 18.596 | 1.054 |
| `Analyzer\FactoryFakerFilter::filterIssue` | 2035/2035 | 15.077 | 0.732 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2/2 | 13.146 | 15.153 |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469/3469 | 12.236 | 10.642 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4/4 | 8.143 | 9.354 |
| `Analyzer\FactoryFakerContracts::scan` | 2/2 | 8.091 | 6.810 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905/1905 | 7.358 | 6.256 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2/2 | 7.282 | 7.340 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4/4 | 6.697 | 9.399 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2/2 | 6.584 | 6.831 |
| `Analyzer\StaticAnalysis\DefensiveArrayGuardProofs::scan` | 2/2 | 6.481 | 6.382 |

## Correctness and limits

All twelve full-workload observations preserve the complete 13,100-record diagnostic multiset, including duplicate records. Source and dependency seals pass. The tuple prefilter passes 237 controls (21 full lexical candidates retained, 181 impossible sites skipped). Issue-source caches pass 43 cold/warm, mutation, source-change, malformed-source and size controls. Three extraction policies match release source facts in 78 comparisons, including CRLF and shifted spans. The model-property prefilter passes 58 controls and the Faker cache passes 305 controls.

Native matrices passed: nullable offsets (63 cases, 17 isolated / 17 full removals, 222 genuine controls), possible tuples, guarded model tuples at one/three workers, null-flow (13 isolated/full removals, 59 controls), validated arrays (15 isolated/full removals, 240 controls), lexical arrows, integrated conditional arrays, the nine-run model-property matrix and full Faker registration/source/priority controls. Original native test deadlines are retained. Full Composer checks and hosted CI have not run.

Five native-controller protocol checks and fourteen Linux-runner/controller checks pass. Source constants cannot silently change when a class is frozen: the one baseline class with a relative include loads from verified byte-identical installed release source, protected by dependency seals. The candidate uses normal Composer autoload instead of the redundant include.

An intermediate seven-class pilot completed both profiles with the full 13,100-record multiset. It was deliberately closed before regular samples to test the combined nine-class candidate. Its interrupted warmup is excluded. It recorded one additional ConfigurationProvider invocation in the candidate; complete diagnostic equality is the correctness invariant. Pilot profiles are separate from final timing samples.

The earlier Larastan level-5 and level-10 medians (153.57 / 158.97 seconds) were collected in a separate series with different load history and check sets. They do not establish a causal speed ratio or equal diagnostic depth. No new PHPStan-level equivalence is asserted.

The candidate remains local and uncommitted. Raw application diagnostics and paths are retained only in private local receipts. [Full aggregate data](cpu-profile-and-optimization-2026-10-17-pass-2.json).

## Fresh rotating comparison with Larastan

A subsequent three-profile series rotates the final candidate, Larastan level 5 with the seven original configuration ignores, and Larastan level 10 with configuration ignores cleared. Every profile occupies each position once. Three regular samples per profile exclude its warmup. All use fresh two-CPU/six-GiB scopes, pinned runtimes, the same sealed source/dependencies and independently verified full diagnostic multisets. The candidate is additionally checked against the final paired study reference. This table compares elapsed work under these configurations; different rule sets and retained source annotations prevent equating diagnostic depth.

| Tool/configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Final Laramago candidate | 3 | 146.27 (135.99-150.57) | 261.98 (258.56-285.40) | 13100 |
| Larastan level 5, seven configuration ignores | 3 | 46.85 (46.03-50.09) | 88.28 (86.58-94.02) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 50.32 (46.42-51.30) | 94.20 (86.98-95.26) | 8373 |

Raw phase timestamps and separate system/scope pressure snapshots are retained in the aggregate JSON. Shared VM scheduling and frequency remain uncontrolled. These new controls replace historical controls for the current speed comparison; no universal PHPStan-level equivalence or general speed ranking is asserted.
