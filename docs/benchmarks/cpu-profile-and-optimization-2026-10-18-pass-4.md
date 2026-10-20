# Early necessary diagnostic-family guards

Baseline is the exact [accepted shared-parser/factory candidate](cpu-profile-and-optimization-2026-10-18-pass-3.md). Five additional issue filters reject diagnostic messages that cannot pass their existing downstream type or target checks before reading source or requesting native metadata: session array keys, selected chunk collections, closed configuration, guarded request headers and default dates. These early checks only return Keep. Matching diagnostics still follow the original complete physical/source/native proof and the original downstream message checks. The target checks intentionally do not constrain the spelling of argument numbers. No native metadata, runtime configuration or final diagnostic decision is cached.

## Paired ordinary timings

Two alternating pairs; profiles and warmups excluded. Every observation has a fresh two-CPU/six-GiB scope, PHP 8.5.4 / Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off, two analyzer threads/workers and read-only vendor. Source and dependency inventories and pinned executable hashes surround every run.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 2 | 92.29 (86.81-97.77) | 172.74 (162.59-182.89) | 2897.32 |
| candidate | 2 | 80.89 (80.42-81.35) | 150.49 (149.63-151.36) | 2863.94 |

Observed reductions: 12.35% elapsed, 12.88% CPU. Scope peak median change: -33.38 MiB.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 97.77 | 80.42 | 6.12 | 6.07 |
| 2 | candidate / baseline | 86.81 | 81.35 | 5.98 | 6.33 |

## Separate callback CPU profiles

All 14 pinned SDK dispatch sites are instrumented; two terminal workers per variant have zero active calls. Profiles are excluded from speed samples. Nested sections are not added to callback totals. Reentrant metadata requests can shift attribution between callbacks; individual profile changes are descriptive. The table shows the largest absolute CPU changes; all callback rows are in the aggregate JSON.

| Handler | Calls baseline/candidate | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\SessionArrayKeyArgumentFilter::filterIssue` | 146/146 | 3.663 | 0.321 |
| `Analyzer\ClosedConfigurationArgumentFilter::filterIssue` | 146/146 | 2.362 | 0.001 |
| `Analyzer\SelectedChunkCollectionArgumentFilter::filterIssue` | 146/146 | 2.328 | 0.002 |
| `Analyzer\DefaultDateArgumentFilter::filterIssue` | 146/146 | 2.370 | 0.131 |
| `Analyzer\GuardedRequestHeaderArgumentFilter::filterIssue` | 161/161 | 2.141 | 0.072 |
| `Analyzer\EloquentAggregateProjectionProvider::getReturnType` | 263/263 | 4.844 | 3.751 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905/1905 | 6.669 | 7.540 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4/4 | 5.608 | 4.823 |
| `Analyzer\ScalarOperandCompatibilityFilter::filterIssue` | 463/463 | 0.471 | 1.209 |
| `Analyzer\DeprecatedMethodCompatibilityProof::scan` | 2/2 | 2.308 | 1.583 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2/2 | 4.564 | 3.938 |
| `Analyzer\StaticAnalysis\KernelIntersectionCalls::scan` | 2/2 | 2.553 | 1.949 |

Largest remaining candidate callback costs:

| Handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 9.792 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 9.580 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 7.788 |
| `Analyzer\CoalesceModelPropertyIssueFilter::filterIssue` | 1905 | 7.540 |
| `Analyzer\DefensiveBoundaryGuardProof::scan` | 4 | 4.823 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 4.283 |
| `Analyzer\FactoryFakerContracts::scan` | 2 | 4.066 |
| `Analyzer\StaticAnalysis\FiniteMapArgumentProofs::scan` | 2 | 3.938 |

## Correctness and limits

All 5 targeted suites passed on native Linux with their original deadlines. The five final native suites exercise closed configuration, selected chunk collection arguments, session array keys, guarded headers and default dates, including actual positive witnesses, unsafe residual Errors, source/native metadata mutations, replacement vetoes, exact restoration and one/three-worker full-registry parity. The previous thirteen-class baseline remains independently qualified by its completed study and source hashes. Seven controller protocol checks pass. All eight full-workload observations preserve every field and duplicate in the 13,100-record diagnostic multiset. The entire delivered PHP-source inventory matches the frozen overlay. Unmapped source files, worker/presets and Composer runtime configuration match the installed release. All 584 selected original inputs and their copied workload bytes still match the initial snapshot.

Two-pair local evidence is limited by changing VM load and uncontrolled CPU frequency. Full Composer checks and hosted CI have not run. Historical ratios from separate timing series are not multiplied. Candidate remains local and uncommitted.

## Fresh rotating comparison with Larastan

Three profiles rotate through all three positions, with three samples each after excluded warmups. All use the same fresh two-CPU/six-GiB scopes, runtimes, read-only dependencies and sealed application input. Independent full diagnostic multisets are verified for every profile; the candidate also matches the earlier accepted full multiset.

| Configuration | N | Elapsed median (range), s | CPU median (range), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Current eighteen-class candidate | 3 | 75.72 (75.71-77.45) | 140.33 (139.81-144.08) | 13100 |
| Larastan level 5, seven original configuration ignores | 3 | 42.05 (41.48-45.04) | 78.54 (77.73-84.60) | 0 |
| Larastan level 10, configuration ignores cleared | 3 | 41.81 (41.46-42.11) | 77.79 (76.99-78.16) | 8373 |

Rule sets differ and source annotations remain. Diagnostic counts do not establish equivalent depth or a universal speed ranking. Phase timestamps and pressure snapshots are retained in JSON. Ratios from separate timing series are not multiplied.

## Repeated public depth matrix

All 33 defect/control pairs were rerun across PHPStan levels 0-10, the separate default-config-types level-10 control and Mago with/without the extension: 924 marked observations and all 23 threshold oracles passed. Fixture/oracle bytes and every selected finding match the previous native matrix. This is a separate correctness experiment on an invented minimal Laravel application with read-only dependencies; fixture and migration bodies are not invoked by the harness.

The finite matrix does not assign one PHPStan-equivalent level to Laramago. Full Composer checks and hosted CI remain unrun.

## Excluded series and temporary-cache recovery

An earlier rotating series stopped at observation 7 after an incomplete PHPStan report; every observation in that series is excluded from speed comparisons. The shared temporary filesystem was found completely full. The previous runner did not preserve the inner analyzer streams, so the precise PHPStan error cannot be recovered. Fifty-six completed PHPStan cache directories, verified by their generated container contents to belong to this workload, were removed (about 2.7 GiB). Both directories from the failed observation were preserved. Application source, dependencies and benchmark receipts were retained.

The replacement series starts from fresh warmups. Its runner differs from the paired-study runner only by failure-only private stream capture after the timed command. A synthetic malformed-output protocol check verifies exact stream retention; it is not analyzer acceptance. Completed PHPStan caches are verified and removed after each successful observation, outside the timed interval. A free-space preflight prevents starting another observation on an exhausted temporary filesystem.

[Full aggregate data](cpu-profile-and-optimization-2026-10-18-pass-4.json).
