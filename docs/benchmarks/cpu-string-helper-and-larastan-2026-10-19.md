# Full workload: projected str-helper metadata lookup

The baseline is the exact admitted package from the [shared traversal study](cpu-shared-walk-and-larastan-2026-10-19.md). The candidate changes only StringHelperProvider. After the existing installed-helper signature, docblock and exact-body checks, it requests declared __call method locations, keeps owners within the same physical helper file and function span, and filters the original class-name order. Empty projection/intersection retains the original complete lookup. The original final class location, containment, method-shape checks and result caching remain. No anonymous-class naming convention, application execution, vendor patch, cache-budget change or request-reference reuse is introduced.

Four excluded complete warmups precede eight four-way balanced rounds, reversing the second cycle. Each profile occupies each position twice; baseline/candidate order is balanced four times each. Every observation uses a fresh two-CPU/six-GiB scope. PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off; Mago 1.48.1 uses two analyzer threads and one PHP extension worker. PHPStan uses at most two processes with fresh caches; dependencies are read-only.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago, 1 PHP worker | 8 | 110.56 (88.81-163.30) | 98.43 | 1385.15 |
| Candidate Laramago, 1 PHP worker | 8 | 114.07 (90.42-136.62) | 91.26 | 1359.61 |
| Larastan L5, seven configuration ignores | 8 | 105.72 (91.04-136.21) | 98.14 | 1176.80 |
| Larastan L10, configuration ignores cleared | 8 | 117.76 (90.10-156.94) | 102.30 | 1185.04 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

All valid slow observations remain. JSON includes excluded warmups, per-command pressure, exact order, paired observations and deterministic descriptive resampling. This does not remove shared-host load or frequency drift.

| Round | Previous Laramago, s | Candidate Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 116.40 | 136.62 | 136.21 | 104.22 |
| 2 | 146.37 | 91.08 | 92.92 | 103.88 |
| 3 | 163.30 | 108.15 | 106.58 | 156.94 |
| 4 | 104.73 | 90.42 | 120.85 | 136.40 |
| 5 | 103.50 | 110.18 | 114.62 | 116.41 |
| 6 | 99.28 | 124.45 | 91.04 | 119.43 |
| 7 | 130.78 | 117.97 | 104.87 | 119.11 |
| 8 | 88.81 | 133.47 | 98.64 | 90.10 |

All 63 native suites pass, including the installed-helper matrix, aliases, three-worker control, string argument/return controls and the previous 59 suites. Every full Mago observation preserves every field and duplicate occurrence of all 13,100 diagnostic records. Complete source packages, all 584 selected PHP/config inputs and 15,497 dependency files are checked before and after observations.

A separate full-diagnostic selectivity profile reduces cached class metadata from 9,826 to 167 records. This is a class-entry count, not a whole-command memory percentage. The older count comes from valid afterAnalysis callback checkpoints in a composite profile rejected for its last shutdown snapshot. The observer overwrite regression is reproduced and fixed: the new first-terminal-per-activity snapshot is preserved before Worker destruction. Full diagnostic, input, terminal and selectivity checks pass for the candidate. Instrumented timing is excluded from the table; absolute CPU and final-heap values across different load epochs/boundaries are not causal comparisons.

Admission requires Elapsed/CPU medians improve; at least six of eight paired elapsed and CPU rounds improve; median scope peak grows no more than 5 percent; all 63 native suites, full-diagnostic/selectivity checks and sealed inputs pass.

The experiment remains private and is not promoted to working sources.
Full Composer checks and hosted CI remain unrun. The measured root extension-host configuration uses workers = 1 while the shared preset is unchanged; this does not establish optimal concurrency for larger hosts. Different rule sets and source annotations prevent equating analysis depth with a PHPStan level.

[Complete aggregate observations](cpu-string-helper-and-larastan-2026-10-19.json).
