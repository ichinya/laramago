# Full workload: combined runtime and memory optimization

Eight modules combine three read-only scan projections with exact 32-bit call-span storage, compact structural and literal source facts preserving the original 16-file FIFO and failed-parse hits, and precise native method projection for the anonymous str-helper class. Native helper signature/body/location checks, original class ordering and complete lookup fallback remain. Source/native authority, ambiguity, cancellation, lifecycle and cache budgets are preserved. Completed source batches retain at most 1,024 hashes. After query eviction, only the requested policy is recomputed from a fresh owned tree; failed requested extraction does not mark a new source complete. Existing tree/query budgets and physical freshness checks are unchanged. No application code or bootstrap is executed.

The baseline is the exact admitted coalesce-facts package. Four excluded full warmups precede eight balanced four-way rounds. Each configuration occupies each position twice; baseline/candidate order is balanced four times each. Commands use fresh two-CPU/six-GiB scopes; PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off, Mago 1.48.1 uses two threads and one extension worker, and PHPStan uses at most two processes with fresh caches.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Admitted coalesce-facts Laramago | 8 | 64.31 (63.25-72.16) | 59.66 | 1358.17 |
| Combined runtime and memory Laramago | 8 | 61.48 (60.33-66.76) | 56.99 | 1298.22 |
| Larastan L5, seven ignores | 8 | 82.46 (80.69-86.73) | 80.41 | 1177.90 |
| Larastan L10, configuration ignores cleared | 8 | 83.50 (82.72-91.27) | 81.90 | 1185.13 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

| Round | Baseline, s | Candidate, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 68.71 | 66.76 | 86.73 | 85.01 |
| 2 | 63.97 | 62.62 | 82.50 | 83.60 |
| 3 | 72.16 | 62.32 | 80.99 | 91.27 |
| 4 | 67.46 | 61.44 | 83.87 | 82.77 |
| 5 | 63.91 | 61.52 | 83.33 | 84.86 |
| 6 | 64.66 | 60.84 | 82.43 | 83.41 |
| 7 | 63.90 | 60.33 | 80.69 | 82.80 |
| 8 | 63.25 | 60.93 | 82.28 | 82.72 |

All 24 native invocations across 23 files pass with their original Composer heap flags and arguments. Independent original-code oracles compare 3,210 scanner states and exceptions across 151 scenarios including the 580 real PHP inputs, 87 returned-graph ownership controls, and 26,100 isolated/batched readonly policy comparisons across 21 policies. Every full Mago observation preserves all fields and duplicate occurrences of the same 13,100 diagnostic records. Complete source packages and all sealed source/config/dependency inputs remain unchanged. Exact selected memory modules also retain 39,356 original structural checks, 7,808 literal graph/site checks, 146 actual parser/FIFO/failure controls, and 63 prior native string-helper suites. Those linked controls verify individual module identities; the 24 new native invocations and every full 13,100-record observation verify the new composition.

A separate 1,039-check real-eviction control reproduces the old unwanted sibling replay and verifies the new requested-only behavior, bounded source hashes, retry of failures, output ownership and initialization cleanup. Fresh original-state and all21-policy graph oracles run again on the exact eight-module package.

The real str-helper proof succeeds in the excluded profile, with 167 cached class records and 1 projected method query. This is an entry count at the captured boundary, not a memory percentage.

A separate full-diagnostic instrumented profile records PHP live memory at the same after-analysis-handler/before-response boundary: 381.73 MiB for baseline and 305.44 MiB for candidate. The root walk is untruncated within its declared opaque-object boundaries. Probe overhead follows the copied snapshot and is excluded from ordinary observations. These phase values do not establish whole-command scope-peak savings; the ordinary table measures that separately.

Admission rules were fixed before measurements: elapsed and CPU medians improve, at least six of eight paired elapsed and CPU wins, no growth in median scope peak, faster than both Larastan profiles, and all source/native/diagnostic controls pass. Observed evaluation: {"checks": {"cpu_median_improves": true, "elapsed_median_improves": true, "faster_than_both_larastan_profiles": true, "scope_peak_median_does_not_grow": true, "six_paired_cpu_wins": true, "six_paired_elapsed_wins": true, "source_native_diagnostic_and_input_controls_pass": true}, "failed_criteria": [], "passed": true}.

The exact measured candidate is promoted locally, uncommitted and unpublished.
All valid slow observations and pressure records remain in JSON. Shared-host load and CPU frequency remain uncontrolled. Different rule sets prevent a universal PHPStan-level equivalence. The exact admitted candidate passes strict Composer validation and the complete 502-command script, including the new public replay regression. Hosted CI is NOTRUN.

[Complete observations](cpu-memory-runtime-combination-and-larastan-2026-10-20.json). [Previous admitted study](cpu-coalesce-facts-and-larastan-2026-10-20.md).

The exact admitted sources additionally pass the refreshed 33-pair/14-configuration marked depth matrix and all 23 PHPStan threshold oracles. See [final-source depth verification](analyzer-depth-memory-runtime-2026-10-20.md).

See [final-source native Composer verification](native-full-check-final-2026-10-20.md).
