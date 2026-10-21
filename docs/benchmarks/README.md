# Benchmarks

This page keeps the method and a compact result summary in Git. Detailed reports, CPU profiles, depth matrices, full observations and failed/rejected series are distributed as [release 0.1.7 assets](https://github.com/ichinya/laramago/releases/tag/0.1.7).

- [Download all benchmark evidence (ZIP)](https://github.com/ichinya/laramago/releases/download/0.1.7/laramago-benchmarks-0.1.7.zip).
- [Per-file SHA-256 manifest and source commits](https://github.com/ichinya/laramago/releases/download/0.1.7/laramago-benchmarks-0.1.7.manifest.json).
- [Archive and manifest SHA-256 checksums](https://github.com/ichinya/laramago/releases/download/0.1.7/laramago-benchmarks-0.1.7.sha256).

The ZIP preserves 81 previously published files: six current Markdown/JSON study pairs, their previous index and 68 intermediate/historical reports. Report bytes and capture-time status fields are unchanged. Paths under `docs/benchmarks/` retain their relative layout. The manifest identifies the immutable commit for each file; later snapshots take precedence for matching paths. Historical source packages and diagnostic counts differ, so their measurements must not be pooled. Git history and existing release tags retain their original reports.

## Final workload results

The Linux and Windows cases select the same 584 PHP/config inputs. Both use PHP 8.5.4, Mago 1.48.1, two logical processors, two Mago threads, one extension worker, at most two PHPStan processes, 2-GiB PHP heaps and a 6-GiB scope/job limit. PHPStan L5 retains seven existing ignores; L10 clears configuration ignores. Rule sets differ and these results do not establish a universal PHPStan-level equivalence.

| Platform | Configuration | Measured runs | Elapsed median, s | Memory peak median, MiB |
| --- | --- | ---: | ---: | ---: |
| Linux | Optimized Laramago | 8 | 61.48 | 1298.22 |
| Linux | Larastan L5 | 8 | 82.46 | 1177.90 |
| Linux | Larastan L10 | 8 | 83.50 | 1185.13 |
| Windows | Mago without extension | 4 | 10.99 | 835.72 |
| Windows | Optimized Laramago | 4 | 143.23 | 1212.47 |
| Windows | Larastan L5 | 4 | 186.04 | 824.61 |
| Windows | Larastan L10 | 4 | 121.71 | 834.61 |

Linux memory is the cgroup peak including charged file cache; Windows memory is peak committed process-tree memory, including console hosts. These metrics cannot be compared directly. Host load, CPU frequency, antivirus and file caches are uncontrolled; full ranges, CPU times and pressure observations are in the archive. Laramago's Windows elapsed median is lower than L5 and higher than L10, with higher memory peaks than both. An initial Windows run failed under system commit exhaustion; its 370-second incomplete observation is retained as a failure, excluded from accepted performance samples.

Every accepted Laramago observation preserves all fields and duplicate occurrences of its 13,100-record reference diagnostic multiset. Linux compares against its admitted baseline; Windows compares against its Windows warmup. Equal counts alone do not prove cross-OS diagnostic equality. The live original application's unrelated frontend drift and failed whole-tree postcheck are retained; the complete measured-copy, selected PHP/config and dependency checks pass.

## Method and tools

1. Use a disposable application copy. Pin analyzer/dependency versions and record complete source/config/dependency path and hash inventories. Rediscover paths and bytes after every command to catch additions, removals and renames.
2. Separate excluded warmups from measurements. Balance configuration order; the final Linux study uses eight four-way rounds and Windows uses four Williams-balanced rounds. Use fresh PHPStan caches and record exact flags, ignores, limits, failures and host pressure.
3. Measure wall time, process-tree CPU and the platform-specific memory peak. Windows assigns each suspended analyzer to its Job Object before resuming it. Capacity guards require stable physical/commit headroom and abort only the owned process tree if pressure becomes unsafe.
4. Compare complete diagnostic multisets, preserving duplicate records. Keep failed, malformed and incomplete observations; do not silently retry or include them as successful timings. Retain source identity and diagnostic evidence alongside performance data.

The reproducible tooling stays in Git: [Linux baseline/candidate runner](../../scripts/benchmark-native.py), [Windows runner](../../scripts/benchmark-windows.py), [handler CPU profiling](../../scripts/benchmark-cpu.py), [public depth runner](../../scripts/benchmark-depth.php), [depth fixtures](../../tests/fixtures/analyzer-depth/cases.php), [protocol tests](../../tests/benchmark-protocol.py) and [Windows accounting/protocol tests](../../tests/benchmark-windows.py). Python runners expose their required inputs through `--help`; the depth runner requires `--dependencies`, `--root`, `--mago` and `--output`. Save generated reports under ignored `var/`, then publish reviewed evidence as release assets.

The measured production source package passes strict Composer validation and the complete 502-command Linux test script. The refreshed depth matrix covers 33 defect/control pairs across 14 configurations and 23 PHPStan threshold oracles; it retains known coverage gaps. Windows protocol tests and native process-tree accounting/timeout controls pass. These are local checks; hosted CI is NOTRUN. The detailed archive records exact source identities, validation boundaries and failures.
