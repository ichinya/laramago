# Full workload: three source-only scans share one traversal batch

The baseline is the exact admitted source-tree compression package. The candidate changes DefaultDateArgumentFilter and its plugin, RememberedGuardedGetterProvider and DeprecatedMethodCompatibilityProof. Three stateless projections derive source call shape, getter candidates and named class spans inside the existing shared traversal batch. Date registers during initialization and retains its first-batch fallback. Paths, current-byte dispatch tokens, hashes, lifecycle and cancellation decisions, scan caps and native metadata authority remain outside cached facts at their original sites. Cache budgets are unchanged; no source or application body is executed.
Four excluded complete warmups precede eight four-way balanced rounds, reversing the second cycle. Each profile occupies each position twice; baseline/candidate order is balanced four times each. Every observation uses a fresh two-CPU/six-GiB scope. PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off; Mago 1.48.1 uses two analyzer threads and one PHP extension worker. PHPStan uses at most two processes with fresh caches; dependencies are read-only.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago, 1 PHP worker | 8 | 74.90 (72.01-102.41) | 69.67 | 1318.95 |
| Candidate Laramago, 1 PHP worker | 8 | 74.34 (68.99-83.72) | 68.85 | 1280.42 |
| Larastan L5, seven configuration ignores | 8 | 89.30 (83.05-99.29) | 87.38 | 1178.14 |
| Larastan L10, configuration ignores cleared | 8 | 89.25 (86.33-100.12) | 87.15 | 1184.62 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

All valid slow observations remain. JSON includes excluded warmups, per-command pressure, exact order, paired observations and deterministic descriptive resampling. This does not remove shared-host load or frequency drift.

| Round | Previous Laramago, s | Candidate Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 72.66 | 71.69 | 83.81 | 86.33 |
| 2 | 72.01 | 68.99 | 83.05 | 87.04 |
| 3 | 74.70 | 71.70 | 83.14 | 91.25 |
| 4 | 77.11 | 83.72 | 94.55 | 91.06 |
| 5 | 73.30 | 81.53 | 89.44 | 91.86 |
| 6 | 75.11 | 74.62 | 92.01 | 87.44 |
| 7 | 102.41 | 75.11 | 99.29 | 100.12 |
| 8 | 76.18 | 74.07 | 89.17 | 86.36 |

All 64 native suites pass, including Date, guarded getters, local captured-this bindings and deprecated-method native DTO/source/metadata controls. Independent copies of the original scanners match every state field and exception in 3,082 checks over 91 scenarios, including 580 real PHP files and split batches, cancellation, duplicates, byte/entry caps, invalid syntax and abstract Facade swap declarations. All 18 direct/batched projections match in 22,620 graph/output/nonmutation comparisons. Equal-work source samples preserve requested policy outputs and all three scanner-state fingerprints. Every full Mago observation preserves every field and duplicate occurrence of all 13,100 records. Complete source packages, 584 selected PHP/config inputs and 15,497 dependency files are checked before and after observations.

In six alternating source-only pairs, CPU median changes from 10.0218 to 6.9885 seconds and PHP allocator peak from 79.52 to 64.50 MiB. Both variants request the same original 15 policy outputs and run the same three scanners; the candidate additionally precomputes their three pure projections. These isolated operations include identical output/state verification and are excluded from the full-command table.

A separate full-diagnostic CPU/heap profile verifies the three new source policy registrations and actual Date initialization. Their three scan callbacks are exercised. All 13,100 records, sealed inputs and explicit terminal observer checks pass. Inclusive callback CPU can overlap; component timers are not an additive exclusive breakdown or whole-command speedup. Instrumented timing is excluded from ordinary observations. The exact production sources subsequently passed [the full 501-command native Composer check](native-full-check-2026-10-20.md); hosted CI remains unrun.

Admission requires Elapsed/CPU medians improve; at least six of eight paired elapsed and CPU rounds improve; median scope peak grows no more than 5 percent; candidate remains faster than both Larastan profiles; all 64 native suites, original-scanner state and exception controls, full diagnostic and sealed input checks pass.

Observed admission checks: {"checks": {"all_native_and_source_controls_pass": true, "at_least_six_paired_cpu_wins": true, "at_least_six_paired_elapsed_wins": true, "cpu_median_improves": true, "elapsed_median_improves": true, "faster_than_both_larastan_profiles": true, "full_diagnostics_and_input_integrity": true, "scope_peak_median_within_five_percent": true}, "failed_criteria": [], "paired_cpu_wins": 6, "paired_elapsed_wins": 6, "passed": true}.

The exact measured candidate is promoted locally, uncommitted and unpublished.
The exact production sources subsequently passed [the full 501-command native Composer check](native-full-check-2026-10-20.md); hosted CI remains unrun. The measured root extension-host configuration uses workers = 1 while the shared preset is unchanged; this does not establish optimal concurrency for larger hosts. Different rule sets and source annotations prevent equating analysis depth with a PHPStan level.

[Complete aggregate observations](cpu-scan-facts-and-larastan-2026-10-20.json).

The existing source-projection ownership regression additionally registers all three new policies and mutates returned nodes across repeated queries, checking statelessness, source nonmutation and consumer isolation. It passes against the exact candidate sources; its existing Composer command is preserved.
