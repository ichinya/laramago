# CPU profiling and optimization: case 01

Measured on 16 October 2026. Completed local study. This follows the [depth and timing study](analyzer-depth-and-timing-2026-10-16.md) and [earlier cache/reader pilots](optimization-check-2026-10-16.md). Their historical observations remain separate.

## What the CPU profile found

The pinned SDK dispatch was instrumented at its 14 callback sites using process `getrusage()` CPU deltas and monotonic elapsed intervals. The observer exports public Laramago callback names, counters and aggregate times. Vendor is mounted read-only; Composer class maps and reflection verify the isolated copies. Both completed profiles preserve the exact canonical multiset of all 13,196 diagnostic records, including every field and duplicate occurrence. Private source paths, messages and diagnostic fingerprints are not exported.

Both profiles contain 515,011 callback invocations and 334 handler/method rows. Terminal snapshots from both workers have zero active calls. The release observer saw five overlapping invocations; their combined inclusive CPU was only 0.000442 s. The candidate saw none. Tables sum isolated callback CPU; inclusive intervals remain in JSON. Elapsed intervals from different workers are never summed into command elapsed time.

| Release handler | Calls | Isolated CPU, s |
| --- | ---: | ---: |
| `Analyzer\PossibleCallbackTupleArgumentFilter::filterIssue` | 2092 | 302.01 |
| `Analyzer\GuardedLocalModelTupleArgumentFilter::filterIssue` | 2089 | 290.65 |
| `Analyzer\ModelPropertyArgumentFilter::filterIssue` | 2088 | 204.00 |
| `Analyzer\ValidatedArrayContractPlugin::filterIssue` | 1020 | 152.92 |
| `Analyzer\GuardedNullFlowIssueFilter::filterIssue` | 1168 | 120.89 |
| `Analyzer\NullableCollectionOffsetIssueFilter::filterIssue` | 11 | 118.09 |
| `Analyzer\StaticAnalysis\StoredReferencePossibleWrites::scan` | 2 | 78.36 |
| `Analyzer\FactoryFakerFilter::filterIssue` | 2094 | 75.07 |
| `Analyzer\StaticAnalysis\SimpleXmlProvenance::scan` | 2 | 58.45 |
| `Analyzer\StaticAnalysis\RefreshedModelProperties::scan` | 4 | 51.47 |
| `Analyzer\EloquentFactoryProvider::getReturnType` | 3458 | 51.28 |
| `Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls::scan` | 2 | 49.35 |

Release scan callbacks used 1045.17 CPU-s; the three leading argument filters used 796.65 CPU-s. All isolated callbacks used 2625.64 CPU-s against 2661.11 worker CPU-s at terminal snapshots and 2737.46 child CPU-s for the whole command. Terminal snapshots precede final process cleanup; this is not an exhaustive native/transport/cleanup partition.

The nested syntax-cache operation was called 28,832 times and used 800.34 CPU-s. The guarded fresh-source reader helper was called 15,348 times and used 734.69 CPU-s. Reader counts include all helper invocations, including rejected paths; they are not counts of successful filesystem reads or parser completions. These intervals are inside callback time and must not be added to it. They include parsing/name resolution, tree reconstruction and source checks performed by those helpers; metadata work outside those helpers is not assigned to them.

The two tree caches recorded 14,448/14,384 calls with 1055/1021 hits and 13,224/13,137 evictions. Unique serialized payloads were 128.49/131.86 MiB against a 64 MiB byte budget. The entry peak was 393, below the 1024 entry limit. Sequential whole-source scans repeatedly evict the next scan's inputs. The profile establishes this workload's locality problem; it does not establish a universal best cache size.

## The retained optimization

[SourceArgumentDeclarationContracts](../../src/Analyzer/SourceArgumentDeclarationContracts.php) now accepts an optional necessary argument shape from the three callers. After validating the native Error envelope and local annotation, it rejects only source expressions that cannot pass the caller's later AST condition: a named variable or direct property fetch. Parentheses, comments, dynamic/braced variables and unknown policies retain the complete physical/native proof. This shortcut can only return Keep; it cannot authorize diagnostic suppression. Existing proof paths remain responsible for every removal. Production syntax-cache behavior is unchanged.

| Filter | Reader calls before | Reader calls after | Profile CPU before, s | Profile CPU after, s |
| --- | ---: | ---: | ---: | ---: |
| `PossibleCallbackTupleArgumentFilter` | 5069 | 3271 | 302.01 | 99.58 |
| `GuardedLocalModelTupleArgumentFilter` | 5146 | 3348 | 290.65 | 99.07 |
| `ModelPropertyArgumentFilter` | 3569 | 1287 | 204.00 | 46.78 |

Selected filter reader calls fell from 13784 to 7906 (42.64% fewer). All guarded-reader calls fell from 15348 to 9470 (38.30% fewer). All 13,196 complete diagnostic records remained equal; callback counts and the public detection matrix are checked independently.

**Raw profile timing differences are not accepted as an optimization speedup.** Unchanged scan code also moved from 1045.17 to 453.31 CPU-s, despite identical observer/SDK/cache/reader source hashes and 28,832 syntax calls in each profile. Workstation/VM scheduling and CPU frequency were not exclusive or controlled. The CPU tables locate costs; operation counts demonstrate avoided work; separate uninstrumented samples assess elapsed performance.

## Repeated timings

| Configuration | N | Elapsed median (min-max), s | CPU median (min-max), s | Peak container memory median, MiB |
| --- | ---: | ---: | ---: | ---: |
| release | 3 | 308.84 (304.19-336.69) | 539.73 (530.89-575.76) | 2134.60 |
| argument-prefilter | 3 | 236.68 (233.45-269.88) | 411.24 (403.75-452.94) | 2146.72 |
| larastan | 3 | 48.36 (47.24-53.27) | 85.58 (82.72-94.48) | 1183.73 |
| native | 3 | 7.15 (7.10-7.24) | 4.38 (4.29-4.54) | 847.95 |

In this series, candidate median elapsed time was 23.37% lower and median CPU was 23.81% lower. It was faster in 3/3 round comparisons, but still took 4.89 times the Larastan elapsed time. These are descriptive observations for this workload.

The private workload contains 531 PHP files, 102,378 lines and 4,288,724 bytes. Versions: PHP 8.5.4, Mago 1.48.1, Laramago 0.1.5 plus the four frozen candidate files, PHPStan 2.2.13, Larastan 3.12.0, Laravel 13.31.0. Each observation uses a fresh pinned Linux container with two CPUs, 6 GiB memory, read-only vendor, PHP 2G limits and OPcache/JIT off. Mago uses two threads/workers; PHPStan uses at most two processes. Runtime evaluation/schema collection is disabled. Composer-autoload dependency scope and source roots are checked before analysis.

Larastan uses level 10, zero configuration ignores and a fresh result-cache directory confirmed by its own verbose output. Existing project settings and source annotations remain effective; analyzer check sets are different. Neither the depth witnesses nor timing ratios assign Laramago a single PHPStan level.

Warmups, profiles and failures are excluded from sample medians. Measured rounds rotate release/candidate/Larastan order; native Mago has three separate controls. The legacy completed release warmup is reused only for prewarming. Every successful run verifies the unchanged sealed input and full diagnostic multiset. Elapsed timing ends immediately after `proc_close`; fingerprints/reporting are outside that window. CPU uses child resource usage. Cgroup memory includes processes and charged file cache; it is not a simultaneous sum of PHP RSS. N=3 on one shared workstation is descriptive evidence, not a general speed ranking or a confidence interval.

## Correctness controls and limits

The new parser-based syntax oracle passed 79 controls, including Unicode, parenthesized, commented and dynamic variables. Four focused native suites passed: possible callback tuples, guarded local model tuples, model property arguments and source argument declarations. They exercise genuine removals, complete residual diagnostics and source/SDK mutation controls, including one/three worker cases. Three suites used test-only 600,000 ms request deadlines after an unmodified local control exceeded 120,000 ms; assertions and production source were unchanged. These are local focused checks, not full Composer or hosted CI acceptance.

The public depth follow-up passed: native Mago and candidate Laramago produced 132 fresh marked observations on the same 33 defect/control pairs, exactly matching the archived matrices. Laramago still flags the selected target on 29/33 examples and none of their marked corrected controls. The archived PHPStan levels 0-10 are references; this follow-up does not claim fresh execution of all 924 prior observations. Cache checks also passed: 10 syntax-cache assertions, 28 pure-query assertions and 62 consumer controls, including mutation isolation and invalidation. Their receipts are included in the aggregate JSON.

Thirteen benchmark-protocol tests passed after the timing-window correction: eight controller mocks and five Linux-runner tests using synthetic analyzers. They check failure retention, input additions/removals/renames, and diagnostic seals; they are separate from native analyzer correctness evidence.

PHP GC counters are retained as raw elapsed seconds. Destructor/free intervals occur inside collector time and are not additive CPU categories. Semantics were checked against the pinned [GC implementation](https://github.com/php/php-src/blob/php-8.5.4/Zend/zend_gc.c) and [API conversion](https://github.com/php/php-src/blob/php-8.5.4/Zend/zend_builtin_functions.c).

## Rejected experiments and failures

A DEFLATE level-1 cache experiment kept the 64 MiB encoded budget and independent ASTs, with a plain fallback when zlib was unavailable. Fifteen assertions passed both with and without compression functions. Its initial candidate/release warmups were 860.38/1010.62 s; a later candidate observation took 1731.75 s (2115.23 CPU-s), despite full diagnostic equality. It was rejected and reverted, including encoding-specific tests. These legacy observations remain in JSON and are excluded from the final series.

The legacy elapsed window included the post-command binary fingerprint. A separate control measured 0.079-0.429 s for that operation; it does not explain multi-minute variation. The runner was corrected before this study and its snapshot/hash is retained.

One candidate warmup hit a 600,000 ms extension request timeout at 611.24 s, exit 2, with an incomplete analyzer report. It is retained as a failure, never as a completed performance sample. The restarted study uses the same 1,800,000 ms request deadline for both versions and a 3600 second whole-command deadline. Earlier 192 MiB cache/reader pilots remain separate; neither capacity increases nor shared-reader caching were adopted.

## Next measured optimization target

The largest remaining structural cost is repeated full-source parsing/traversal across scan handlers and duplicated worker indexes. Evaluate a source-local pass that produces bounded immutable extraction facts for multiple policies. Keep current disk freshness, native metadata, lifecycle state and final diagnostic decisions outside those syntax caches. A larger serialized-tree cache or a one-file microbenchmark alone has not demonstrated the required whole-workload benefit. Metadata RPC/transport wall time has not been separately attributed by this CPU profile.

`NullableCollectionOffsetIssueFilter` is also an outlier: 11 calls used 118.09 CPU-s. Inspection shows that `NullableCollectionOffsetContract::current()` audits ancestors/known descendants, and its private `source()` reparses fresh physical source on each request. These reads are outside the instrumented guarded-reader helper. Their individual CPU contribution is not yet measured; profile them before introducing bounded exact-byte syntax reuse, and retain post-metadata source-hash checks and descendant mutation controls.

## Reproduction

The pinned disposable case volume and installed read-only release dependencies are prerequisites; the private application is not published. The public witnesses and protocol checks can be reproduced independently. Use a fresh output directory each time. The initial command creates a diagnostic reference only when no reference exists; use `--verify-diagnostics` for an existing reference.

```text
python scripts/benchmark-cpu.py --volume DISPOSABLE_CASE_VOLUME --output-dir NEW_REFERENCE_DIRECTORY --request-timeout-ms 1800000 --timeout 3600
python scripts/benchmark-cpu-study.py --volume DISPOSABLE_CASE_VOLUME --output-dir NEW_STUDY_DIRECTORY --reference-profile-dir NEW_REFERENCE_DIRECTORY --candidate-files src/Analyzer/SourceArgumentDeclarationContracts.php src/Analyzer/PossibleCallbackTupleArgumentFilter.php src/Analyzer/GuardedLocalModelTupleArgumentFilter.php src/Analyzer/ModelPropertyArgumentFilter.php --variant-name argument-prefilter --rounds 3 --request-timeout-ms 1800000 --test-script tests/source-argument-syntax.php --profile-before-samples
LARAMAGO_BENCHMARK_LINUX_TESTS=1 python tests/benchmark-protocol.py
```

See the [aggregate observations and all handler rows](cpu-profile-and-optimization-2026-10-16.json). Candidate/runner/instrumentation source hashes are public package hashes; private input and diagnostic fingerprints remain inside the volume.
