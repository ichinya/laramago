# Native Linux CPU profiling and optimization: case 02

Measured on 17 October 2026. Completed local study.

## Workload and controls

A fresh disposable copy contains 531 PHP files, 102,439 physical lines and 4,292,786 bytes under the four common roots `app`, `tests`, `scripts/testing`, `testo.php`. All 584 selected source/configuration input paths and bytes matched the original checkout after copying. Three inputs differ from the historical case-01 snapshot; its measurements and diagnostic seal are not reused. The original application was not modified. An additional audit covers 1284 paths in source/reference trees, including routes, language files and resources: their directory/file ctimes precede the first successful analysis, and their final path/byte snapshot matches the additional audit snapshot. This corroborating check is separate from the per-observation selected-input seal; generated bootstrap/cache and public/storage are excluded. Environment files, application SQLite databases, storage data and node_modules are excluded from the copy.

PHP 8.5.4 and Mago 1.48.1 run directly in WSL, from Linux temporary storage. Xdebug is absent. Each observation has a fresh systemd user scope limited to two CPUs and 6 GiB; PHP processes have a 2 GiB limit, OPcache/JIT off, and read-only vendor. Mago uses two threads and two extension workers. PHPStan uses at most two processes. Runtime/schema evaluation is disabled; database URL and session settings are explicitly isolated.

Installed application dependencies are preserved; the extension fixture contains the exact tagged Laramago 0.1.6 source. Composer autoload files were regenerated offline with plugins and scripts disabled. Only the candidate class is overlaid, with reflection verifying the loaded path. Source, dependency and executable checks surround every paired observation.

One warmup per control is excluded. Three rounds rotate the control order, so every tool occupies every position once. Each observation uses a fresh analysis result cache and checks the full diagnostic multiset against its own control reference. These controls precede the paired Laramago series; they are not an interleaved universal speed ranking.

| Control | N | Elapsed median (min-max), s | CPU median (min-max), s | Diagnostics |
| --- | ---: | ---: | ---: | ---: |
| Mago without extensions | 3 | 10.46 (8.46-11.45) | 9.51 (6.34-9.89) | 21531 |
| PHPStan/Larastan level 5, seven existing ignores | 3 | 153.57 (120.51-345.63) | 265.91 (195.68-522.65) | 0 |
| PHPStan/Larastan level 10, config ignores cleared | 3 | 158.97 (153.02-245.57) | 257.23 (232.48-394.83) | 8373 |

The CPU PSI `some` counter deltas span approximately 4.05-18.20% for native Mago, 16.55-36.55% for level 5, and 23.52-26.58% for level 10. These indicate time with at least one task stalled for CPU, not CPU utilization. Endpoint averages cover trailing 10/60/300-second windows, not the whole observation. System PSI covers the shared VM; dedicated-scope PSI is retained separately. See the [Linux PSI documentation](https://docs.kernel.org/accounting/psi.html).

The two PHPStan configurations keep source annotations and the remaining project settings. Different check sets and existing ignores prevent equating Laramago with one PHPStan level. The [archived depth matrix](analyzer-depth-and-timing-2026-10-16.md) remains a separate witness-level reference.

## CPU attribution and the candidate

Both full profiles preserve the same 13,100 complete diagnostic records and their multiplicities. All 14 pinned SDK dispatch sites are instrumented. Each profile records 515,437 completed callbacks; both workers have terminal snapshots and zero active calls. Callback CPU columns below use isolated intervals. Nested nullable sections are separate and are not added to callback time. The outer `current()` interval can include SDK work while awaiting metadata; the source/syntax/lookup sections are synchronous.

| Handler | Calls | Baseline isolated CPU, s | Candidate isolated CPU, s |
| --- | ---: | ---: | ---: |
| `Analyzer\GuardedLocalModelTupleArgumentFilter::filterIssue` | 2030 | 54.80 | 72.35 |
| `Analyzer\PossibleCallbackTupleArgumentFilter::filterIssue` | 2033 | 54.70 | 71.70 |
| `Analyzer\ValidatedArrayContractPlugin::filterIssue` | 1020 | 49.51 | 63.51 |
| `Analyzer\GuardedNullFlowIssueFilter::filterIssue` | 1272 | 45.68 | 63.20 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 25.42 | 14.19 |
| `Analyzer\ModelPropertyArgumentFilter::filterIssue` | 2029 | 24.75 | 37.82 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 21.99 | 17.56 |
| `Analyzer\FactoryFakerFilter::filterIssue` | 2035 | 20.92 | 28.02 |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3469 | 18.12 | 27.67 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4 | 14.57 | 10.45 |
| `Analyzer\StaticAnalysis\DefensiveArrayGuardProofs::scan` | 2 | 14.07 | 7.51 |
| `Analyzer\StaticAnalysis\ValidatedIntegerCalls::scan` | 2 | 12.94 | 7.34 |

The candidate changes only `NullableCollectionOffsetContract::source()`: use the existing 64 MiB / 1024-entry exact-byte syntax cache, with independent resolved ASTs. Every fresh filesystem read, one-MiB limit, source identity and post-metadata hash check remains. Native metadata and suppression decisions are not cached.

| Nested section | Baseline calls | Candidate calls | Baseline CPU, s | Candidate CPU, s |
| --- | ---: | ---: | ---: | ---: |
| `nullable-source-syntax` | 3486 | 3486 | 25.14 | 10.78 |
| `nullable-source` | 3486 | 3486 | 25.44 | 11.10 |
| `nullable-class-lookup` | 678 | 678 | 1.29 | 1.90 |
| `nullable-current` | 11 | 11 | 29.87 | 18.07 |

Candidate nullable syntax lookups: **3486 calls, 3466 hits, 20 misses** (99.43% hits). This establishes avoided repeated parse work. The fresh physical-source helper still runs 3486 times. Other cache size/payload/eviction counters were not instrumented.

Profile command timings are excluded from performance medians. Unchanged argument filters also used more CPU in the candidate profile, while unchanged scan handlers used less; CPU frequency and scheduling were not controlled. The 345.40/406.97-second profile commands do not establish the overall effect.

## Uninstrumented paired timings

| Variant | N | Elapsed median (min-max), s | CPU median (min-max), s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| baseline | 4 | 539.90 (315.87-1041.01) | 976.32 (593.36-1366.12) | 2213.38 |
| candidate | 4 | 519.27 (256.19-878.08) | 955.74 (486.11-1596.84) | 2236.43 |

Four alternating pairs place each variant first twice. Both profiles and both warmups are excluded. Every one of the twelve Laramago observations completed with the same full 13,100-record multiset, unchanged selected source and dependency inputs, and pinned executables.

| Round | Order | Baseline elapsed, s | Candidate elapsed, s | Baseline CPU PSI some, % | Candidate CPU PSI some, % |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 | baseline / candidate | 1041.01 | 583.31 | 49.05 | 12.10 |
| 2 | candidate / baseline | 579.08 | 878.08 | 10.03 | 13.20 |
| 3 | baseline / candidate | 500.72 | 455.23 | 12.51 | 11.00 |
| 4 | candidate / baseline | 315.87 | 256.19 | 4.63 | 4.14 |

Observed medians are 3.82% lower for elapsed time and 2.11% lower for CPU; the candidate is faster in 3/4 pairs. Median scope peak memory increases by 1.04%. Ranges overlap widely, phase trends are substantial, and CPU pressure differs between paired observations. **These local data do not isolate a robust whole-command speedup.** The targeted repeated-parse reduction is supported by the full-workload profile and operation counts.

The earlier Larastan level-10 control median is 158.97 s versus 519.27 s for the candidate. The series are separate and have different load histories and check sets; this is not an exact causal speed ratio. These measurements do not demonstrate Laramago beating Larastan.

## Correctness and limits

The new source reader test passed 12 controls: fresh-parser equivalence, actual bounded-cache use, nested mutation isolation, duplicate-content file identities, same-size/same-mtime edits, post-metadata freshness, malformed/oversized/deleted source and restoration. Existing syntax/query/consumer suites passed 10/28/62 controls. The full native nullable matrix passed 63 cases, including 17 isolated and 17 full removals, 222 genuine SDK/source controls, full residual diagnostics and one/three-worker equality at the original 120,000 ms test deadline.

Fourteen benchmark protocol tests passed, including six Linux-runner synthetic-analyzer tests; three native-study controller tests also passed. Mocked processes and synthetic analyzers are protocol evidence only. Full Composer checks and hosted CI have not been run for this candidate.

An initial control setup failed during JSON decoding at the parent PHP default 128 MiB. That attempt is excluded; successful observations use the same parent/child 2 GiB limits. Earlier source-seal migration failures and an interrupted old-workload profile remain separate local receipts. No private application paths, diagnostic messages or input/diagnostic fingerprints are exported.

See the [aggregate observations and all handler rows](cpu-profile-and-optimization-2026-10-17.json).

## Next optimization target

The remaining leading CPU consumers are the two tuple argument filters, validated-array checks and null-flow checks, together with repeated scan hooks. `GuardedStringCastContracts::read()` still reparses fresh source and rebuilds parent maps. Evaluate necessary-syntax rejection and bounded source-local extraction facts before full reconstruction; retain physical freshness and native proof for every removal. A new whole-workload comparison is required for any further change.

## Reproduction

Prepare a disposable native Linux application copy with the exact tagged 0.1.6 package, pinned runtime/dependencies and read-only vendor. The private application is not distributed. Native correctness gates must identify the tested production source by SHA-256; the gate schema and this run's receipt are in `native_correctness_gate` in the aggregate JSON. Use a fresh output directory.

```text
python3 -B scripts/benchmark-native.py --project /tmp/DISPOSABLE_CASE --php /PINNED/php --mago /PINNED/mago --output-dir NEW_STUDY_DIRECTORY --gates NATIVE_GATES_RECEIPT.json --baseline-ref 0.1.6 --expected-laramago-version 0.1.6 --rounds 4 --candidate-files src/Analyzer/NullableCollectionOffsetContract.php
python3 -B tests/benchmark-native.py
LARAMAGO_BENCHMARK_LINUX_TESTS=1 python tests/benchmark-protocol.py
```

The Docker-controller experiment added while setting up this run was removed after switching to the native controller; its mock tests are not part of the retained patch. Local raw receipts are retained separately. The analyzer candidate remains uncommitted; no release is created by this study.
