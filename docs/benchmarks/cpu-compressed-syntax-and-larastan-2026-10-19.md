# Full workload: lossless source syntax snapshot compression

The baseline is the exact admitted configuration-only Inertia eligibility package. The candidate changes only the representation of retained CodebaseScanSyntax trees, using optional zlib level-1 compression when it is smaller. Missing zlib keeps the original representation. Admission and eviction still use the original uncompressed sizes, entry limit and LRU order; compressed storage does not enlarge the cache cohort. Restored trees preserve exact serialized bytes, resolved names, spans, comments and independent mutable ownership. Query caching, input freshness and diagnostics are unchanged.
Four excluded complete warmups precede eight four-way balanced rounds, reversing the second cycle. Each profile occupies each position twice; baseline/candidate order is balanced four times each. Every observation uses a fresh two-CPU/six-GiB scope. PHP 8.5.4 has a 2-GiB heap with OPcache/JIT off; Mago 1.48.1 uses two analyzer threads and one PHP extension worker. PHPStan uses at most two processes with fresh caches; dependencies are read-only.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago, 1 PHP worker | 8 | 83.30 (73.23-92.50) | 74.26 | 1370.53 |
| Candidate Laramago, 1 PHP worker | 8 | 79.88 (74.69-90.39) | 73.79 | 1231.18 |
| Larastan L5, seven configuration ignores | 8 | 97.16 (86.22-108.27) | 91.91 | 1177.90 |
| Larastan L10, configuration ignores cleared | 8 | 103.54 (93.16-336.19) | 98.76 | 1186.43 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

All valid slow observations remain. JSON includes excluded warmups, per-command pressure, exact order, paired observations and deterministic descriptive resampling. This does not remove shared-host load or frequency drift.

| Round | Previous Laramago, s | Candidate Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 73.23 | 84.71 | 98.34 | 95.16 |
| 2 | 89.49 | 82.70 | 86.98 | 93.16 |
| 3 | 86.59 | 90.39 | 108.27 | 105.33 |
| 4 | 80.00 | 78.90 | 89.14 | 108.16 |
| 5 | 87.96 | 77.51 | 86.22 | 95.71 |
| 6 | 77.43 | 80.86 | 95.97 | 102.76 |
| 7 | 74.29 | 74.69 | 104.77 | 104.33 |
| 8 | 92.50 | 76.94 | 101.00 | 336.19 |

All 60 native suites pass, including stored/direct callback and factory contracts, projection ownership, resolved syntax, original cache budgets, freshness and eviction consumers. Independent fresh-parser controls compare cold/warm mutable graphs on 580 real PHP files. Missing-zlib controls pass; 19,140 real projection/output/nonmutation comparisons and all 14 source-only warmup/sample fingerprints and LRU cohorts match. Budget controls continue to test original uncompressed sizes and also verify actual stored-byte accounting. Every full Mago observation preserves every field and duplicate occurrence of all 13,100 records. Complete source packages, 584 selected PHP/config inputs and 15,497 dependency files are checked before and after observations.

In six alternating source-only pairs, PHP allocator peak changes from 165.47 to 55.06 MiB while CPU changes from 4.6061 to 5.2136 seconds. These are isolated source operations, excluded from the full-command table. The source-only CPU increase is retained.

A separate full-diagnostic profile verifies stored tree bytes of 18,008,198, compared with 162,826,715 in the earlier baseline terminal snapshot. The observer's existing bytes field counts stored bytes; the separate original-size budget and exact LRU cohort are verified independently. This component comparison does not establish a whole-command memory saving. All 13,100 records, sealed inputs and terminal observer checks pass; instrumented timing is excluded from the table. Full Composer checks and hosted CI remain unrun.

Admission requires Median scope peak decreases by at least 5 percent, at least six of eight paired memory observations improve, elapsed and CPU medians regress no more than 5 percent, candidate elapsed median stays below both Larastan profiles; all 60 native suites, independent graphs and original-size/LRU cohort controls, exact full diagnostics and sealed inputs pass.

The exact measured candidate is promoted locally, uncommitted and unpublished.
Full Composer checks and hosted CI remain unrun. The measured root extension-host configuration uses workers = 1 while the shared preset is unchanged; this does not establish optimal concurrency for larger hosts. Different rule sets and source annotations prevent equating analysis depth with a PHPStan level.

[Complete aggregate observations](cpu-compressed-syntax-and-larastan-2026-10-19.json).

Depth verification was refreshed on 20 October: all 33 defect/control pairs across 14 configurations and all 23 PHPStan threshold oracles pass. Both marked Mago matrices match the archived reference. The original historical byte check failed on CRLF-to-LF normalization; separate offline verification retained its receipt and reused the completed outputs without rerunning analyzers. See [fresh depth matrix and verification](analyzer-depth-refresh-2026-10-20.md).
