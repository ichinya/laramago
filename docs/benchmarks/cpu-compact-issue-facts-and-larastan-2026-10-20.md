# Full workload: compact source facts in issue filters

StructuralArrayContractFilter retains ordered scalar call/property sites instead of its expanded-file AST cache. LiteralForeachSelectionReturnFilter retains declaration headers, every matching return site including failed proofs, and the consumed pure proof nodes. Its original 16-file FIFO, parse-failure hits and initialization reset are preserved with encoded compact snapshots. Empty statement lists use an explicit guard for PHP 8.5. Live native signatures, source authority, ambiguity, spans and query byte/entry budgets are unchanged.

The baseline is the exact admitted coalesce-facts package. Four excluded full warmups precede eight balanced four-way rounds. Each configuration occupies each position twice; baseline/candidate order is balanced four times each. Commands use fresh two-CPU/six-GiB scopes; PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off, Mago 1.48.1 uses two threads and one extension worker, and PHPStan uses at most two processes with fresh caches.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Admitted coalesce-facts Laramago | 8 | 64.42 (63.35-73.78) | 59.69 | 1383.92 |
| Compact issue-facts Laramago | 8 | 64.58 (63.09-67.64) | 59.80 | 1335.96 |
| Larastan L5, seven ignores | 8 | 82.39 (81.31-92.82) | 80.40 | 1176.14 |
| Larastan L10, configuration ignores cleared | 8 | 82.82 (81.37-84.89) | 81.26 | 1185.16 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

| Round | Baseline, s | Candidate, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 73.78 | 63.93 | 82.13 | 81.37 |
| 2 | 65.04 | 63.30 | 81.63 | 82.45 |
| 3 | 63.70 | 64.40 | 81.31 | 82.55 |
| 4 | 73.62 | 67.61 | 82.36 | 83.10 |
| 5 | 63.56 | 64.81 | 92.82 | 81.88 |
| 6 | 63.35 | 63.09 | 90.35 | 84.33 |
| 7 | 63.80 | 64.76 | 82.42 | 83.18 |
| 8 | 65.92 | 67.64 | 82.72 | 84.89 |

All 15 combined native suites pass with their original Composer heap flags and arguments. The exact modules separately pass 39,356 original structural-method/output-and-native-query checks, 7,808 original literal header/proof/site comparisons, and 146 actual parser-entry/FIFO/failure-cohort checks. Each source oracle parses 580 real PHP files plus synthetic cases without executing application bodies. Every full Mago observation preserves all fields and duplicate occurrences of the same 13,100 diagnostic records. Complete source packages and all sealed source/config/dependency inputs remain unchanged.

A separate full-diagnostic instrumented profile records PHP live memory at the same after-analysis-handler/before-response boundary: 381.73 MiB for baseline and 336.83 MiB for candidate. The root walk is untruncated within its declared opaque-object boundaries. Probe overhead follows the copied snapshot and is excluded from ordinary observations. These phase values do not establish whole-command scope-peak savings; the ordinary table measures that separately.

Admission rules were fixed before measurements: elapsed and CPU medians improve, at least six of eight paired elapsed and CPU wins, no growth in median scope peak, faster than both Larastan profiles, and all source/native/diagnostic controls pass. Observed evaluation: {"checks": {"cpu_median_improves": false, "elapsed_median_improves": false, "faster_than_both_larastan_profiles": true, "scope_peak_median_does_not_grow": true, "six_paired_cpu_wins": false, "six_paired_elapsed_wins": false, "source_native_diagnostic_and_input_controls_pass": true}, "failed_criteria": ["elapsed_median_improves", "cpu_median_improves", "six_paired_elapsed_wins", "six_paired_cpu_wins"], "passed": false}.

The candidate remains private and is not promoted.
All valid slow observations and pressure records remain in JSON. Shared-host load and CPU frequency remain uncontrolled. Different rule sets prevent a universal PHPStan-level equivalence. The earlier scan-facts package passed the full 501-command Composer script; the subsequent coalesce baseline and this candidate have focused native gates, not a repeated full script. Hosted CI is NOTRUN.

[Complete observations](cpu-compact-issue-facts-and-larastan-2026-10-20.json). [Previous admitted study](cpu-coalesce-facts-and-larastan-2026-10-20.md).
