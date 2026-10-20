# Full workload: compact declaration ownership, worker count and WSL recovery

Completed local study on the same 531-file case. Unmodified baseline behavior is compared with an exact-source lazy declaration catalogue that keeps expanded nodes only for actual supported proofs. Necessary assertions without a proof retain analyzed bytes and materialize a complete file on demand. Existing physical/native freshness, cancellation, bounds, collision handling, proof order and stable mutable node identities remain.

The baseline is the complete local [pass16 source package](cpu-profile-and-optimization-2026-10-18-pass-16.md), not an unmodified published 0.1.6 installation. Both variants retain the earlier forty-five-class release overlay; only two production modules differ in this experiment.

Five excluded full warmups, then five rotating rounds: each of the five configurations occupies every position once. Every observation uses a fresh two-CPU/six-GiB scope, PHP 8.5.4, Mago 1.48.1, 2-GiB PHP heaps, OPcache/JIT off and read-only dependencies. Mago keeps two analyzer threads; its PHP worker count is varied explicitly. PHPStan uses at most two processes and a fresh analysis cache.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Baseline, 2 PHP workers | 5 | 113.65 (92.94-132.03) | 107.33 | 2875.22 |
| Candidate, 2 PHP workers | 5 | 102.34 (88.28-104.20) | 95.77 | 1952.08 |
| Candidate, 1 PHP worker | 5 | 99.97 (82.82-123.24) | 93.55 | 1490.80 |
| Larastan L5, seven configuration ignores | 5 | 97.42 (91.44-184.75) | 95.04 | 1177.91 |
| Larastan L10, configuration ignores cleared | 5 | 94.09 (89.68-143.10) | 91.80 | 1180.79 |

Memory is the peak of the dedicated process scope, including charged file cache. It is not a PHP allocator peak or a sum of individual maximum RSS values.

Observed one-worker candidate elapsed ratios: larastan-level5: 1.026, larastan-level10: 1.063. Ratios describe this completed series only. Different check sets and annotations prevent assigning an equivalent PHPStan level.

The separately measured one-worker configuration sets workers = 1 in the existing root [extension-hosts.laramago] table, retaining its worker command and other settings. It was measured with two Mago analyzer threads on a two-CPU scope. This does not change the shared preset or establish the best worker count for larger hosts.

| Round | Baseline 2 workers, s | Candidate 2 workers, s | Candidate 1 worker, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: | ---: |
| 1 | 113.65 | 102.34 | 93.42 | 96.56 | 94.09 |
| 2 | 132.03 | 102.84 | 123.24 | 184.75 | 143.10 |
| 3 | 104.51 | 101.58 | 118.84 | 156.20 | 123.46 |
| 4 | 114.31 | 104.20 | 99.97 | 97.42 | 92.51 |
| 5 | 92.94 | 88.28 | 82.82 | 91.44 | 89.68 |

All slow observations are retained. The JSON includes CPU/memory pressure and descriptive paired variability; five rounds on a shared VM do not remove host-load or CPU-frequency effects. Worker-count benefits include avoiding duplicate initialization and scan state; they are reported separately from the two-worker source change.

All 55 targeted native suites pass at their original heaps/deadlines. Independent original/new checks cover 340 graph/lifecycle controls and 581 complete scanner-state comparisons across 580 real PHP files. All full Mago observations retain every field and duplicate occurrence of all 13,100 diagnostics. Full source/dependency inventories are checked before and after every observation.

The isolated scan retained 15 materialized classes instead of 117, with the same 18 proofs in 11 files. Its live heap increment changed from 337.34 to 169.13 MiB; this is a separate scan measurement, not the whole-command peak above.

A separate instrumented baseline profile identified the largest live-heap increases during scan callbacks (summed over two workers):

- DirectCallbackReferenceEffects::scan: 921.63 MiB.
- AggregateProjectionCalls::scan: 302.68 MiB.
- RefreshedModelProperties::scan: 15.29 MiB.
- StoredReferencePossibleWrites::scan: 11.64 MiB.
- ArrayAssertionCalls::scan: 9.93 MiB.

These deltas include shared cache construction and garbage collection; they are not exclusive retained ownership. AggregateProjectionCalls also starts the shared extraction batch for 15 policies. Instrumented runs are excluded from the ordinary timing series.

An excluded one-worker profile of the exact admitted candidate preserved all 13,100 records. The PHP worker consumed 80.51 CPU seconds. The largest isolated callback CPU totals were AggregateProjectionCalls::scan (6.81 s), CoalesceModelPropertyIssueFilter::filterIssue (2.66 s), GuardedNullFlowIssueFilter::filterIssue (1.81 s), OrdinaryMixedAssignmentFilter::filterIssue (1.76 s) and ScriptMixedAssignmentFilter::filterIssue (1.69 s). Isolated totals omit overlapping intervals during metadata RPC; inclusive intervals can overlap, so these are not an exclusive additive CPU breakdown. The JSON retains all callback rows.

WSL interruption was reproduced with a low-load foreground control: an OOM event put init.scope under OOMPolicy=stop, followed by a 90-second stop timeout that killed WSL init and unrelated foreground processes. A narrow init.scope drop-in now sets OOMPolicy=continue. Kernel OOM handling and all memory limits remain enabled; the unsuccessful linger change was reverted. A 147-second foreground control and the complete native suites ran successfully after the repair. See the [systemd scope policy documentation](https://www.freedesktop.org/software/systemd/man/262/systemd.scope.html).

All 584 selected PHP/config inputs match the original. Ancillary frontend/build changes are recorded separately. The lost historical diagnostic reference is not asserted identical; this series creates a fresh exact pass16 baseline reference.

The exact measured candidate is promoted to the local working sources; changes are uncommitted and unpublished. Full Composer checks and hosted CI remain unrun.

[Complete aggregate observations](cpu-memory-and-wsl-2026-10-19.json).
