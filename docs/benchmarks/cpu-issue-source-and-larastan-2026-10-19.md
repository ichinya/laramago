# Full workload: avoiding repeated source work in mixed-assignment filters

The baseline is the exact locally admitted candidate from the [completed memory and WSL study](cpu-memory-and-wsl-2026-10-19.md), with one PHP worker. Two production modules change. ScriptMixedAssignmentFilter skips parsing when the authoritative source bytes contain neither include nor require in any ASCII letter case. Every supported include assignment requires one of these literal PHP keywords; strings/comments can cause conservative extra parses. All original diagnostic-envelope and cancellation checks remain.

OrdinaryMixedBindings obtains the same resolved mutable syntax through CodebaseScanSyntax, whose serialized cache returns independent trees. It calculates the same source SHA-256 once per inspection instead of once per candidate binding. Source limits, frame traversal, reference/annotation refusal and output fields remain unchanged.

Four excluded full warmups precede four rotating rounds. Every configuration occupies every position once. Slow observations remain. Every command uses a fresh two-CPU/six-GiB scope, PHP 8.5.4 with a 2-GiB heap and OPcache/JIT disabled, Mago 1.48.1 with two analyzer threads, and read-only dependencies. Both Laramago configurations use one PHP worker; PHPStan uses at most two processes and a fresh analysis cache. These are fresh observations, not ratios against older host-load epochs.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago, 1 PHP worker | 4 | 105.20 (97.01-125.52) | 97.51 | 1478.49 |
| Candidate Laramago, 1 PHP worker | 4 | 97.60 (90.82-103.12) | 89.82 | 1490.61 |
| Larastan L5, seven configuration ignores | 4 | 106.87 (94.73-181.01) | 103.47 | 1177.50 |
| Larastan L10, configuration ignores cleared | 4 | 105.22 (85.90-108.84) | 101.28 | 1181.53 |

Dedicated scope memory.peak includes all charged processes and file cache, not PHP heap or a sum of child maximum RSS values.

Candidate elapsed ratios: baseline-one-worker: 0.928, larastan-level5: 0.913, larastan-level10: 0.928. These describe this series on a shared VM. Different rules, ignores and source annotations prevent interpreting the comparison as equal analysis depth.

| Round | Previous 1 worker, s | Candidate 1 worker, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 110.93 | 92.45 | 94.73 | 85.90 |
| 2 | 125.52 | 102.75 | 99.57 | 105.21 |
| 3 | 99.48 | 103.12 | 114.17 | 105.23 |
| 4 | 97.01 | 90.82 | 181.01 | 108.84 |

The JSON retains all warmups, ordinary observations, CPU/memory pressure and descriptive paired variability. No valid slow run was removed or retried.

The controller observation handle and namespace disappeared after 19 successful observations. No controller/runner remained. Those 19 records and their raw files are preserved byte-for-byte; only the missing final Larastan L5 observation is resumed after all source, binary, dependency and existing reference checks. The exact cause of this separate interruption is unconfirmed. This is an interrupted and resumed series, not a continuous run.

Independent original/new source checks cover all 580 real PHP files and 20 synthetic cases with 1,800 comparisons, including repeated inspection and source nonmutation. All 57 native suites pass at their original 512-MiB heaps and native request deadlines: the original 55 plus both affected rule suites. The include suite adds upper/mixed-case keywords and conservative string/comment controls; its 43 genuine cases and 27 SDK controls pass. The ordinary assignment suite preserves complete native reports for 41 cases, one/three/single controls and 261 genuine envelope negatives.

Every full Mago observation retains every field and duplicate of all 13,100 diagnostics using the same sealed reference as the previous completed study. All 584 selected PHP/config inputs, all 15,497 dependency files and complete source packages are verified before and after observations. The historical lost reference from the older source epoch is not reasserted.

Source admission criteria were fixed before these ordinary measurements: Both elapsed/CPU medians lower; at least 3 of 4 paired rounds improve for both metrics; median scope peak no more than 5 percent higher; all independent gates and full diagnostic multisets pass.

The exact tested candidate is promoted to local working sources, uncommitted and unpublished.
 Full Composer checks and hosted CI remain unrun.

The separately tested one-worker configuration sets workers = 1 in the existing root [extension-hosts.laramago] table while retaining its worker command and other settings. The shared preset is unchanged; larger hosts may need a different worker count.

[Complete aggregate observations](cpu-issue-source-and-larastan-2026-10-19.json).
