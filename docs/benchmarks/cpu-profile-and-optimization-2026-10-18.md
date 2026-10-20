# Adaptive AST cache capacity

Completed native Linux study on the same anonymous case 02. Baseline is the exact [previous nine-class candidate](cpu-profile-and-optimization-2026-10-17-pass-2.md), with its full completed native study and source hashes verified before freezing. The sole additional production change is `CodebaseScanSyntax`.

## Cause and implementation

The 64-MiB AST cache could not retain the approximately 132-MiB unique serialized-tree set per worker. The source collectors repeatedly revisit the same files in hook order. The measured baseline recorded 25,726 repeat misses; exact-source trees were repeatedly parsed/resolved/serialized after eviction. The independent pure-query cache had zero evictions.

The candidate keeps 64 MiB on heaps up to 512 MiB; larger heaps may use one eighth of their configured memory, capped at 160 MiB. A reduced PHP heap contracts the cache before the next hit. The 1024-entry cap, LRU behavior, independent mutable AST returns, exact-byte keys, registration clearing and original query-cache bounds remain. Native metadata and diagnostic decisions are not cached.

Every observation uses pinned PHP 8.5.4 / Mago 1.48.1, a fresh two-CPU/six-GiB scope, 2-GiB PHP limits, OPcache/JIT off, two threads/workers and read-only vendor. Selected source/dependency paths and bytes and executable hashes surround observations. The original application is untouched.

## Ordinary timings

Two alternating pairs put each variant first once. Both full profiles and both warmups are excluded.

| Variant | N | Elapsed median (range), s | CPU median (range), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 2 | 120.09 (116.04-124.13) | 227.74 (220.99-234.50) | 2236.84 |
| candidate | 2 | 88.32 (88.02-88.63) | 166.04 (165.52-166.56) | 2838.94 |

Observed median reductions: 26.45% elapsed and 27.09% CPU. Scope peak median changes by +602.11 MiB. These are local two-pair observations; shared load varies and frequency is not fixed.

| Round | Order | Baseline, s | Candidate, s | Baseline system CPU PSI some, % | Candidate system CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 124.13 | 88.02 | 5.43 | 5.30 |
| 2 | candidate / baseline | 116.04 | 88.63 | 4.27 | 5.01 |

## Separate instrumented cache probes

These probes measure operation counts and nested CPU sections, not ordinary speed samples. Both preserve the complete 13,100-record multiset. AST CPU includes parse/resolution/serialization and cloning; nested sections are not added to callback totals.

| Probe | AST lookups | Hits | Misses | Repeat misses | Evictions | AST CPU, s |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| before | 31252 | 4429 | 26823 | 25726 | 26463 | 106.74 |
| after | 31252 | 30152 | 1100 | 0 | 0 | 35.79 |

## Correctness and limits

All eight study observations preserve every field and duplicate occurrence in the full 13,100-record multiset. Both additional cache probes verify the previous accepted candidate reference. Seventeen budget controls cover 256/512-MiB, 1/2-GiB and unlimited heaps, real large retention, contraction on a warm hit, byte accounting, exact syntax equality and independent query facts. All nineteen targeted source/consumer/native suites passed, including the previous native tuple, null-flow, array, model-property, Faker and nullable matrices. Seven native-controller protocol tests passed, including immutable baseline-overlay capture and rejection of incomplete/changed previous snapshots. Full Composer checks and hosted CI have not run.

The earlier rotating Larastan comparison applies to the previous nine-class candidate. This study does not establish a new speed ratio against Larastan or a common PHPStan-equivalent depth. Source annotations and different check sets remain relevant. No ratio from different timing series is multiplied into an overall speedup.

Candidate remains local and uncommitted. [Full aggregate data](cpu-profile-and-optimization-2026-10-18.json).
