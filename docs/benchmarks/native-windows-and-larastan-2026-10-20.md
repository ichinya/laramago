# Native Windows performance on the same application

The selected 584 source/config inputs are byte-identical to the Linux case. The disposable native Windows copy uses all 587 analyzer source files of published commit 3eb08dc8c930aa162ae0b63e88f9ce6b1cd59020. The measured application-copy and vendor inventories remain unchanged.

Four excluded complete warmups precede four Williams-balanced rounds. Each of four configurations occupies each position once, and every pair runs in both relative orders twice. Full PHPStan runs use fresh caches; parameters are probed separately and the missing result cache is confirmed in each measured command. Within the completed guarded series, no observation is trimmed or retried.

A new capacity guard waits for at least 4 GiB of free physical memory and system commit headroom, stable for ten seconds before each timed command. Only the owned analyzer tree is aborted if either headroom drops below 512 MiB. The guard and pressure abort do not modify the paging file or stop unrelated processes.

| Configuration | N | Elapsed median (range), s | CPU median, s | Peak committed memory median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Mago without extension | 4 | 10.99 (10.59-15.63) | 12.54 | 835.72 |
| Mago + optimized Laramago | 4 | 143.23 (124.15-172.68) | 134.09 | 1212.47 |
| Larastan L5, seven ignores | 4 | 186.04 (117.15-200.01) | 225.37 | 824.61 |
| Larastan L10, configuration ignores cleared | 4 | 121.71 (116.36-223.25) | 202.01 | 834.61 |

Native PHP 8.5.4, Mago 1.48.1, two fixed logical processors, two Mago threads, one extension worker, at most two PHPStan processes, a 2-GiB PHP heap and a 6-GiB job commitment limit. OPcache and JIT are off for children as well. Windows binaries and DLLs execute natively; WSL and Docker are outside the timing path.

The analyzer is created suspended, assigned to its measurement Job Object, verified inside that job, and only then resumed. Real parent/child allocation and CPU controls pass, as does the timeout control that stops only the owned tree. Windows console hosts are included. The memory metric is [Windows PeakJobMemoryUsed](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-jobobject_extended_limit_information); it is committed process memory and cannot be compared directly with the Linux cgroup peak including file cache.

The application, dependency and helper paths/bytes are rediscovered before and after each command. Every result preserves all fields and duplicate occurrences of its configuration's excluded Windows warmup diagnostics. Laramago diagnostic count: 13100. Matching a Linux count alone is not a cross-OS diagnostic-multiset comparison.

The live original project changed outside the measured copy: 13 existing frontend/build files changed or disappeared. Its 584 selected PHP/config inputs and vendor paths/bytes still match the copy-time inventories. The controller correctly failed its whole-live-origin comparison; that failure is retained. A separate acceptance check verifies the complete immutable-copy series and all Composer autoload locations remain inside that sealed copy. The original frontend changes are preserved.

| Round | Mago, s | Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 11.01 | 129.67 | 199.32 | 120.57 |
| 2 | 10.59 | 156.78 | 172.76 | 122.85 |
| 3 | 10.97 | 124.15 | 117.15 | 116.36 |
| 4 | 15.63 | 172.68 | 200.01 | 223.25 |

Whole-host CPU busy ranged from 46.0% to 88.4% during measured commands. Host load, frequency, antivirus and file-cache state are uncontrolled; all per-command pressure observations remain in JSON.

Settings and rule sets differ from one another and from the OS/runtime details of the Linux study. These are measurements of this workload and settings, not a general Windows/Linux ranking or a universal analyzer-level equivalence.

An earlier unguarded series failed when Windows committed about 255.35 GiB against a 255.87-GiB system limit. PHP reported VirtualAlloc 0x5af and Mago fell back after a worker disconnect. Its incomplete six-command block, including its valid observations, is retained in the JSON; the failed 370-second observation is not a Laramago performance result. The full series above is a fresh, capacity-guarded design, not a resumption or a selection of old timings.

The analyzer source already passed the complete 502-command Composer script on Linux. That suite is not repeated for these documentation and measurement-helper changes; native Windows measurement controls and the full 20-command Windows series are the new evidence. Hosted CI is NOTRUN. Cleanup and benchmark changes remain local and uncommitted.

[Complete observations](native-windows-and-larastan-2026-10-20.json). [Linux study](cpu-memory-runtime-combination-and-larastan-2026-10-20.md). [Benchmark index and historical archive](README.md).
