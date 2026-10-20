# Full workload: compact source facts in scan hooks

ArrayAssertionCalls, ConditionalArrayGuards and ArgvNormalizationAdvisories extract source-only facts in the existing owned batch. ArrayAssertionCalls stores exact call start/end offsets as fixed-width big-endian unsigned32 pairs, instead of decimal strings. The original2MB per-file guard keeps all offsets in range. No native metadata, physical freshness, ambiguity, cancellation, lifecycle or cache byte/entry limit is removed. No expanded syntax tree survives the batch through this cache.

The baseline is the exact admitted coalesce-facts package. Four excluded full warmups precede eight balanced four-way rounds. Each configuration occupies each position twice; baseline/candidate order is balanced four times each. Commands use fresh two-CPU/six-GiB scopes; PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off, Mago 1.48.1 uses two threads and one extension worker, and PHPStan uses at most two processes with fresh caches.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Admitted coalesce-facts Laramago | 8 | 64.45 (62.95-78.37) | 59.75 | 1341.45 |
| Extra scan-facts Laramago | 8 | 61.90 (60.53-75.64) | 57.36 | 1353.77 |
| Larastan L5, seven ignores | 8 | 82.36 (80.49-88.87) | 80.38 | 1179.17 |
| Larastan L10, configuration ignores cleared | 8 | 83.61 (80.76-88.48) | 81.58 | 1182.38 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

| Round | Baseline, s | Candidate, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 65.66 | 61.26 | 82.50 | 83.85 |
| 2 | 78.37 | 61.29 | 83.76 | 81.76 |
| 3 | 62.95 | 64.31 | 80.59 | 88.48 |
| 4 | 63.97 | 60.89 | 80.49 | 85.63 |
| 5 | 62.97 | 75.64 | 83.63 | 83.37 |
| 6 | 64.04 | 62.51 | 82.23 | 80.76 |
| 7 | 71.39 | 62.61 | 88.87 | 86.29 |
| 8 | 64.86 | 60.53 | 80.54 | 81.19 |

All16 native suites pass with their original Composer heap flags and arguments. Independent original-code oracles compare3210 scanner states and exceptions across151 scenarios including the580 real PHP inputs,87 returned-graph ownership controls, and26100 isolated/batched readonly policy comparisons across21 policies. Every full Mago observation preserves all fields and duplicate occurrences of the same13100 diagnostic records. Complete source packages and all sealed source/config/dependency inputs remain unchanged.

A separate full-diagnostic instrumented profile records PHP live memory at the same after-analysis-handler/before-response boundary: 381.73 MiB for baseline and 381.82 MiB for candidate. The root walk is untruncated within its declared opaque-object boundaries. Probe overhead follows the copied snapshot and is excluded from ordinary observations. These phase values do not establish whole-command scope-peak savings; the ordinary table measures that separately.

Admission rules were fixed before measurements: elapsed and CPU medians improve, at least six of eight paired elapsed and CPU wins, no growth in median scope peak, faster than both Larastan profiles, and all source/native/diagnostic controls pass. Observed evaluation: {"checks": {"cpu_median_improves": true, "elapsed_median_improves": true, "faster_than_both_larastan_profiles": true, "scope_peak_median_does_not_grow": false, "six_paired_cpu_wins": true, "six_paired_elapsed_wins": true, "source_native_diagnostic_and_input_controls_pass": true}, "failed_criteria": ["scope_peak_median_does_not_grow"], "passed": false}.

The candidate remains private and is not promoted.
All valid slow observations and pressure records remain in JSON. Shared-host load and CPU frequency remain uncontrolled. Different rule sets prevent a universal PHPStan-level equivalence. The earlier scan-facts package passed the full 501-command Composer script; the subsequent coalesce baseline and this candidate have focused native gates, not a repeated full script. Hosted CI is NOTRUN.

[Complete observations](cpu-extra-scan-facts-and-larastan-2026-10-20.json). [Previous admitted study](cpu-coalesce-facts-and-larastan-2026-10-20.md).
