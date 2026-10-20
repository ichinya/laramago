# Full workload: coalesce source facts

The candidate replaces repeated whole-tree reads in CoalesceModelPropertyIssueFilter with exact-source scalar probe spans and a unique model declaration header retaining both complete magic methods. Physical file checks, post-RPC freshness, native identities, ambiguity decisions and cache byte/entry budgets remain unchanged. Every full Mago observation preserves all fields and duplicate occurrences of the same 13,100 records.

Four complete excluded warmups precede eight balanced four-way rounds. Each configuration occupies each position twice; baseline/candidate order is balanced four times each. Each command uses a fresh two-CPU/six-GiB scope; PHP 8.5.4 has a 2-GiB heap and OPcache/JIT off; Mago 1.48.1 uses two threads and one extension worker. PHPStan uses at most two processes and fresh caches. Dependencies remain read-only.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago | 8 | 72.88 (68.77-86.82) | 67.63 | 1370.26 |
| Coalesce-facts Laramago | 8 | 69.57 (67.22-73.72) | 63.39 | 1353.81 |
| Larastan L5, seven ignores | 8 | 88.35 (85.05-92.82) | 86.20 | 1176.87 |
| Larastan L10, configuration ignores cleared | 8 | 88.74 (86.18-106.99) | 86.68 | 1185.07 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

| Round | Previous Laramago, s | Candidate Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 86.82 | 70.75 | 92.82 | 89.25 |
| 2 | 70.92 | 67.96 | 90.04 | 88.45 |
| 3 | 78.79 | 72.52 | 88.49 | 106.99 |
| 4 | 74.47 | 73.72 | 89.47 | 89.96 |
| 5 | 72.10 | 68.39 | 85.35 | 88.93 |
| 6 | 68.77 | 67.72 | 85.05 | 88.56 |
| 7 | 71.11 | 71.27 | 88.21 | 86.18 |
| 8 | 73.67 | 67.22 | 87.77 | 86.73 |

The independent original-source oracle passes 17,951 checks over 581 real PHP files and 34 synthetic sources, including the actual framework model. It verifies original site selection, complete declaration headers and magic-method graphs, ambiguity and consumer ownership. Twelve related native suites pass with their unchanged Composer flags. All sealed source/config/dependency inputs remain unchanged. The exact baseline previously passed strict Composer validation and its full 501-command script; this candidate has not yet run that entire script.

An excluded instrumented full run preserves all diagnostics. CPU inside the source helper is 0.488 seconds over 578 calls versus 2.293 seconds in the earlier profile. These nested component observations do not establish the whole-command speedup; only the ordinary table does.

Admission is evaluated against the rules fixed before measurement: {"checks": {"cpu_median_improves": true, "elapsed_median_improves": true, "faster_than_both_larastan_profiles": true, "memory_median_within_five_percent": true, "six_paired_cpu_wins": true, "six_paired_elapsed_wins": true, "source_native_diagnostic_and_input_controls_pass": true}, "failed_criteria": [], "passed": true}.

The exact measured candidate is promoted locally, uncommitted and unpublished.
All valid slow observations remain. Pressure, order, per-round changes and excluded warmups are in JSON. Shared host load and CPU frequency remain uncontrolled. Different diagnostic rule sets prevent a universal PHPStan-level equivalence; the old 600+ second epoch is not used as a normalized before/after comparison. Hosted CI is NOTRUN.

[Complete observations](cpu-coalesce-facts-and-larastan-2026-10-20.json). [Baseline full check](native-full-check-2026-10-20.md).

The exact admitted sources additionally pass the refreshed 33-pair/14-configuration marked depth matrix and all 23 PHPStan threshold oracles. See [final-source depth verification](analyzer-depth-optimized-2026-10-20.md).
