# Full workload: configuration-only Inertia policy eligibility

The baseline is the exact admitted package from the shared traversal study. The candidate changes InertiaEntryReferencesHook and ReferenceCatalogs. The missing-page policy checks only whether its existing constructor configuration explicitly marks the page catalog complete before touching syntax or native helper metadata. Missing, false or non-boolean completeness cannot prove absence. Complete configurations keep the original source, native-authority and membership path. Eligibility neither loads pages nor moves filesystem validation earlier; other Inertia policies can still use known pages in partial catalogs. The rejected string-helper lookup change is excluded.
Four excluded complete warmups precede eight four-way balanced rounds, reversing the second cycle. Each profile occupies each position twice; baseline/candidate order is balanced four times each. Every observation uses a fresh two-CPU/six-GiB scope. PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off; Mago 1.48.1 uses two analyzer threads and one PHP extension worker. PHPStan uses at most two processes with fresh caches; dependencies are read-only.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago, 1 PHP worker | 8 | 85.45 (81.54-120.07) | 79.70 | 1383.47 |
| Candidate Laramago, 1 PHP worker | 8 | 77.86 (67.75-86.61) | 67.91 | 1381.72 |
| Larastan L5, seven configuration ignores | 8 | 99.80 (85.71-127.03) | 96.01 | 1178.28 |
| Larastan L10, configuration ignores cleared | 8 | 95.18 (90.21-134.94) | 92.06 | 1185.90 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

All valid slow observations remain. JSON includes excluded warmups, per-command pressure, exact order, paired observations and deterministic descriptive resampling. This does not remove shared-host load or frequency drift.

| Round | Previous Laramago, s | Candidate Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 81.56 | 67.75 | 85.71 | 134.94 |
| 2 | 106.91 | 82.53 | 127.03 | 121.44 |
| 3 | 81.62 | 73.18 | 102.25 | 96.26 |
| 4 | 86.82 | 86.61 | 110.32 | 94.10 |
| 5 | 90.96 | 72.02 | 100.99 | 92.37 |
| 6 | 84.07 | 85.30 | 98.62 | 92.91 |
| 7 | 120.07 | 71.90 | 98.48 | 109.54 |
| 8 | 81.54 | 85.52 | 95.69 | 90.21 |

All eight related native suites pass: the new eligibility/membership oracle, complete and partial catalogs, installed helper/route entry diagnostics, page references, required props, prop types, Vue props and test component references. Additional controls preserve constructor snapshots, lazy filesystem timing and the absence of SDK/source access for unreportable configurations. Every full Mago observation preserves every field and duplicate occurrence of all 13,100 records. Complete source packages, 584 selected PHP/config inputs and 15,497 dependency files are checked before and after observations.

A separate full-diagnostic profile exercises 21,039 calls of the missing-page policy with no complete catalog and records 0.0371 callback CPU seconds. All 13,100 records, sealed inputs and terminal observer checks pass. This component timer does not measure whole-command speedup and is excluded from the table. Full Composer checks and hosted CI remain unrun.

Admission requires Elapsed/CPU medians improve; at least six of eight paired elapsed and CPU rounds improve; median scope peak grows no more than 5 percent; all eight related native suites, full-diagnostic/shortcut checks and sealed inputs pass.

The exact measured candidate is promoted locally, uncommitted and unpublished.
Full Composer checks and hosted CI remain unrun. The measured root extension-host configuration uses workers = 1 while the shared preset is unchanged; this does not establish optimal concurrency for larger hosts. Different rule sets and source annotations prevent equating analysis depth with a PHPStan level.

[Complete aggregate observations](cpu-inertia-eligibility-and-larastan-2026-10-19.json).
