# Shared syntax for fresh physical-source reads

The baseline is the exact [accepted adaptive-cache candidate](cpu-profile-and-optimization-2026-10-18.md). The sole additional production change is `GuardedStringCastContracts`: after a fresh physical read it uses the existing exact-byte resolved AST cache. Native metadata, physical path confinement, source-size limits, analyzed-byte equality and post-metadata hash checks remain fresh. Every read rebuilds parent links for its independent mutable AST.

## Paired ordinary timings

Two alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 2 | 95.56 (93.56-97.55) | 179.47 (175.58-183.37) | 2837.48 |
| candidate | 2 | 84.82 (84.42-85.22) | 158.75 (158.45-159.06) | 2874.89 |

Observed reductions: 11.23% elapsed, 11.54% CPU. Scope peak median change: +37.40 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 97.55 | 85.22 | 5.33 | 5.48 |
| 2 | candidate / baseline | 93.56 | 84.42 | 6.06 | 5.25 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift CPU attribution between providers; individual profile changes are descriptive, not independent causal estimates. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\SessionArrayKeyArgumentFilter::filterIssue` | 146/146 | 6.120 | 2.520 |
| `Analyzer\GuardedRequestHeaderArgumentFilter::filterIssue` | 161/161 | 4.255 | 1.908 |
| `Analyzer\ClosedConfigurationArgumentFilter::filterIssue` | 146/146 | 4.069 | 2.200 |
| `Analyzer\SelectedChunkCollectionArgumentFilter::filterIssue` | 146/146 | 3.865 | 2.143 |
| `Analyzer\DefaultDateArgumentFilter::filterIssue` | 146/146 | 4.050 | 2.346 |
| `Analyzer\ConfigurationFacadeProvider::getReturnType` | 29/29 | 1.017 | 0.011 |
| `Analyzer\ScriptMixedAssignmentFilter::filterIssue` | 2363/2363 | 3.036 | 2.111 |
| `Analyzer\StaticAnalysis\ConditionalArrayGuards::scan` | 2/2 | 1.869 | 1.103 |
| `Analyzer\StaticAnalysis\ReconstructedArrayShapes::scan` | 2/2 | 2.586 | 1.823 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 3.326 | 2.576 |
| `Analyzer\ConfigurationProvider::getReturnType` | 61/61 | 0.003 | 0.671 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4/4 | 4.184 | 3.657 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 11.204 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 8.039 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 7.410 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 6.016 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263 | 5.260 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.656 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 3.881 |
| `Analyzer\StaticAnalysis\AggregateProjectionCalls::scan` | 2 | 3.659 |

## Correctness and limits

All 26 targeted suites passed on native Linux with their original deadlines. Fourteen new physical-reader controls cover independent AST/parent identities, fresh analyzed bytes, current path identity, same-size/same-mtime changes, post-metadata mutation, malformed and oversized input, deleted files and exact restoration. Native consumers include string casts, source arguments, session keys, headers, closure writes, date arguments, tuple/null-flow/array/model-property and Faker matrices. Seven controller protocol checks pass. All eight full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset.

Two-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Three profiles rotate through all three positions, with three samples each after excluded warmups. All use the same fresh two-CPU/six-GiB scopes, runtimes, readonly dependencies and sealed application input. Independent full diagnostic multisets are verified for every profile; Laramago additionally matches the paired-study reference.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current eleven-class candidate | 3 | 105.83 (95.10-109.01) | 188.76 (178.13-198.84) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 50.22 (41.18-50.28) | 94.13 (77.45-94.59) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 48.42 (45.48-58.43) | 90.14 (84.85-104.58) | 8373 |

These local timings compare the actual configurations. Rule sets differ and source annotations remain, so diagnostic counts do not establish equivalent depth or a universal speed ranking. Load snapshots and phase timestamps are retained in JSON.

## Fresh public depth matrix

The current eleven-class candidate was rerun through the public 33 defect/control pairs, all PHPStan levels 0-10, the separate default-config-types level-10 control, and native Mago with/without the extension. All PHPStan and Mago defect/control findings match the previous public matrix. Fixture/oracle hash changes are exactly the verified Windows CRLF to native LF normalization. The harness and identifier-specific oracles passed: 924 marked observations and 23 core threshold oracles. The fixture has a minimal invented Laravel application and read-only dependencies; fixture and migration bodies are not invoked by the harness. This depth matrix is a separate correctness experiment, excluded from timing medians.

| Witness | First PHPStan level | Current Laramago target | Current flagged corrected control |
| --- | ---: | --- | --- |
| unknown-function | 0 | non-existent-function/Error | — |
| unknown-class | 0 | non-existent-class/Error | — |
| undefined-variable | 0 | undefined-variable/Error | — |
| missing-argument | 0 | too-few-arguments/Error | — |
| maybe-undefined | 1 | possibly-undefined-variable/Warning | — |
| magic-property | 2 | non-documented-property/Warning | — |
| unknown-method | 2 | non-existent-method/Error | — |
| incompatible-phpdoc | 2 | docblock-type-mismatch/Error | — |
| return-type | 3 | invalid-return-statement/Error | — |
| property-type | 3 | invalid-property-assignment-value/Error | — |
| unreachable | 4 | unevaluated-code/Help | — |
| impossible-type | 4 | — | — |
| argument-type | 5 | invalid-argument/Error | — |
| missing-parameter | 6 | — | — |
| missing-return | 6 | — | — |
| iterable-value | 6 | — | — |
| union-argument | 7 | possibly-invalid-argument/Error | — |
| union-method | 7 | non-existent-method/Error | — |
| nullable-method | 8 | possible-method-access-on-null/Error | — |
| nullable-property | 8 | possibly-null-property-access/Error | — |
| explicit-mixed-argument | 9 | mixed-argument/Error | — |
| explicit-mixed-method | 9 | mixed-method-access/Error | — |
| implicit-mixed-argument | 10 | mixed-argument/Error | — |
| implicit-mixed-method | 10 | mixed-method-access/Error | — |
| array-shape | 3 | invalid-return-statement/Error | — |
| callable-argument | 5 | invalid-argument/Error | — |
| generic-identity | 3 | invalid-return-statement/Error | — |
| model-property | 3 | invalid-return-statement/Error | — |
| collection-first | 3 | invalid-return-statement/Error | — |
| config-value | 3 | invalid-return-statement/Error | — |
| query-nullable | 8 | possibly-null-property-access/Error | — |
| migrated-property | 3 | invalid-return-statement/Error | — |
| config-default | 3 | invalid-return-statement/Error | — |

The finite matrix combines high-level mixed checks with gaps in missing-annotation checks in analyze mode. It supports a per-witness comparison; no single PHPStan level is assigned to Laramago. Full Composer checks and hosted CI remain unrun.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-2.json).
