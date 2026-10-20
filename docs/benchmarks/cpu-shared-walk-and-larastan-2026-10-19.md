# Full workload: shared source traversal and keyword selection

The baseline is the complete admitted source package from the [previous mixed-assignment study](cpu-issue-source-and-larastan-2026-10-19.md). The candidate changes thirteen production modules. Trusted source-only projections of the same exact root tree reuse a batch-local preorder and type buckets. Standalone, subset and nested independent trees retain the original NodeFinder traversal. Early searches do not force a full preorder. Finally and clear release every temporary root reference. Encoded cache budgets, returned-node isolation, physical/native checks, cancellation and bounds remain unchanged.

The include selector also rejects identifier continuations such as required and included. ASCII-insensitive scans and explicit identifier bytes avoid locale-dependent regular-expression matching; prefixes remain conservative. An independent PHP tokenizer oracle verifies that genuine include/require keywords are never skipped. No analyzed body is executed.

Four excluded full warmups precede eight four-way rounds. Each configuration occupies each position twice. The second cycle reverses the first cycle, balancing baseline/candidate order four times each. Every command uses a fresh two-CPU/six-GiB scope, PHP 8.5.4 with a 2-GiB heap and OPcache/JIT off, Mago 1.48.1 with two analyzer threads, one PHP extension worker, and read-only dependencies. PHPStan uses at most two processes with fresh analysis caches.

| Configuration | N | Elapsed median (range), s | CPU median, s | Scope peak median, MiB |
| --- | ---: | ---: | ---: | ---: |
| Previous admitted Laramago, 1 PHP worker | 8 | 168.62 (114.28-313.63) | 145.59 | 1474.23 |
| Candidate Laramago, 1 PHP worker | 8 | 145.51 (94.18-227.02) | 126.16 | 1468.68 |
| Larastan L5, seven configuration ignores | 8 | 163.96 (105.01-309.58) | 136.84 | 1177.83 |
| Larastan L10, configuration ignores cleared | 8 | 159.48 (104.88-263.39) | 145.80 | 1178.18 |

Dedicated scope memory.peak includes charged processes and file cache; not PHP heap or a sum of child maximum RSS values.

Observed candidate elapsed ratios: baseline-one-worker: 0.863, larastan-level5: 0.887, larastan-level10: 0.912. These describe this completed series only. Different rules and source annotations prevent treating the comparison as equal analysis depth.

| Round | Previous Laramago, s | Candidate Laramago, s | Larastan L5, s | Larastan L10, s |
| --- | ---: | ---: | ---: | ---: |
| 1 | 114.28 | 94.18 | 105.01 | 104.88 |
| 2 | 313.63 | 119.50 | 161.42 | 129.46 |
| 3 | 217.80 | 205.22 | 309.58 | 241.50 |
| 4 | 142.57 | 114.15 | 113.44 | 207.02 |
| 5 | 115.82 | 140.59 | 250.32 | 124.53 |
| 6 | 224.07 | 179.72 | 211.40 | 163.96 |
| 7 | 153.62 | 150.43 | 134.35 | 155.01 |
| 8 | 183.63 | 227.02 | 166.50 | 263.39 |

All valid slow observations remain. JSON retains warmups, full CPU/memory pressure, exact order, paired observations and deterministic descriptive resampling. Host-load or frequency drift is not removed by the design.

The separate pure-source experiment compares all 580 real PHP files and 15 real policies: 19,140 complete graph/output/nonmutation checks pass. Six alternating pairs reduce median extraction CPU from 7.32 to 4.92 s, with equal 165.47-MiB allocator peaks. This is a source extraction microbenchmark with identical verification overhead, not a 33-percent whole-command speed claim.

All 59 native suites pass at their original 512-MiB heaps and native request deadlines, including the original 55, both mixed-assignment suites and two new tests. Additional controls cover 53 ownership/order/exception cases and 66,130 native-tokenizer cases including all 580 real files. Every full Mago observation preserves every field and duplicate of all 13,100 diagnostic records. Complete packages, all 584 selected PHP/config inputs and all 15,497 dependency files are verified before and after observations.

Admission requires Both elapsed/CPU medians improve, at least six of eight paired rounds improve for each, median scope peak grows no more than 5 percent, all independent/native/full-multiset checks pass.

The exact measured candidate is promoted locally, uncommitted and unpublished.
Full Composer checks and hosted CI remain unrun.

The measured configuration sets workers = 1 in the existing root [extension-hosts.laramago] table while retaining its command and other settings. The shared preset remains unchanged; this does not establish an optimal worker count for larger hosts.

[Complete aggregate observations](cpu-shared-walk-and-larastan-2026-10-19.json).

## Excluded CPU and heap profile

A separate terminal one-worker profile preserves all 13,100 diagnostic records and all source/dependency hashes. It records 515,294 callbacks and 249.59 worker CPU seconds. This instrumented run is excluded from every ordinary timing summary. All four generated PHP files pass native syntax checks. Both observers flush before SDK shutdown, with zero active calls/scans.

| Callback | Calls | Isolated calls | Isolated CPU, s | Inclusive CPU, s |
| --- | ---: | ---: | ---: | ---: |
| StaticAnalysis\AggregateProjectionCalls::scan | 1 | 1 | 15.38 | 15.38 |
| DefaultDateArgumentFilter::scan | 1 | 1 | 7.68 | 7.68 |
| CoalesceModelPropertyIssueFilter::filterIssue | 1905 | 1606 | 7.25 | 8.11 |
| DeprecatedMethodCompatibilityProof::scan | 1 | 1 | 6.54 | 6.54 |
| RememberedGuardedGetterScan::scan | 1 | 1 | 6.41 | 6.41 |
| StaticAnalysis\RefreshedModelProperties::scan | 2 | 2 | 4.93 | 4.93 |
| StaticAnalysis\ContextualCollectionMembers::scan | 1 | 1 | 4.91 | 4.91 |
| StaticAnalysis\DirectCallbackReferenceEffects::scan | 1 | 1 | 4.56 | 4.56 |
| FactoryFakerContracts::scan | 1 | 1 | 4.21 | 4.21 |
| StaticAnalysis\ArrayAssertionCalls::scan | 1 | 1 | 3.83 | 3.83 |

These intervals are not an additive exclusive CPU breakdown. Metadata RPC can overlap callbacks; isolated totals omit those overlapping calls. Different VM load and frequency prevent interpreting differences from older absolute profile times as an optimization effect.

| Scan callback | Live heap delta, MiB |
| --- | ---: |
| StaticAnalysis\AggregateProjectionCalls::scan | 151.36 |
| StaticAnalysis\DirectCallbackReferenceEffects::scan | 34.68 |
| StaticAnalysis\ArrayAssertionCalls::scan | 4.97 |
| StaticAnalysis\AssertedPureGetterCalls::scan | 4.89 |
| StaticAnalysis\ForwardedTransactionTemplateCalls::scan | 4.53 |

Scan deltas include shared caches and GC. AggregateProjectionCalls starts the shared 15-policy batch, so its increase is not exclusive ownership. The last scan ends at 372.17 MiB allocated; the later process peak reaches 558.99 MiB allocated and 546.03 MiB live. At terminal flush, live memory is 216.31 MiB while allocated memory remains 532.49 MiB. Encoded tree/query caches account for 155.28/7.96 MiB. These distinguish later analysis peaks from retained live cache and unused allocator pages; they do not prove that trimming terminal pages would lower the earlier command peak.

One read-only WSL observation command timed out with `0x8007274c`. Reconnecting confirmed the same native controller and advancing completed results. Debian and the study were not restarted; no valid observation was repeated. The earlier proven init.scope OOM repair is documented in the [memory and WSL study](cpu-memory-and-wsl-2026-10-19.md).

The two new ownership/tokenizer tests are registered in `composer test`; existing commands remain in their original order. The complete Composer command and hosted CI remain unrun.

## Later heap peak checkpoints

A second excluded profile records 354 bounded callback checkpoints, without source, arguments or diagnostic payloads. It preserves all 13,100 records and source/dependency hashes; CPU and heap observers are terminal with zero active calls/scans, and no checkpoint truncation. A separate synthetic control verifies results, exceptions, public callback labels and a transient allocation that is already freed when the callback returns.

| First checkpoint observing a later peak increase | Boundary | Increase since prior checkpoint, MiB | Live process peak, MiB |
| --- | --- | ---: | ---: |
| EloquentPropertyProvider::getPropertyType | after | 13.00 | 397.67 |
| AssertedPureGetterReturnProvider::getReturnType | before | 11.60 | 421.55 |
| AssertedPureGetterReturnProvider::getReturnType | before | 9.00 | 478.52 |
| CollectionHelperProvider::getReturnType | after | 8.18 | 380.61 |
| EloquentRelationMethodProvider::getReturnType | after | 6.87 | 454.07 |
| ForceFillWriteContractHook::analyze | after | 6.78 | 544.17 |
| InertiaEntryReferencesHook::analyze | after | 6.57 | 533.49 |
| EloquentChunkCallbackProvider::getCallableSignature | after | 6.24 | 436.30 |

Checkpoints identify the first observed peak, not exclusive allocation ownership. Before checkpoints may observe SDK work between callbacks; other Fibers can overlap. No single callback is established as the cause of the whole later heap increase. Peaks advance across property/return providers, callable signatures, issue filters and analyze hooks, including increases first observed before a callback. The next memory experiment should distinguish shared SDK/request/cache retention and GC from one handler retaining the whole difference. No GC policy, cache-budget reduction or allocator trimming has been admitted from these checkpoints alone. The complete ordinary study remains unchanged.

## SDK census quality check

A third excluded profile preserves all 13,100 diagnostics and has terminal CPU, heap and transport components with zero active calls/requests. Its composite check is **rejected** because the last SDK-cache census is empty: PHP shutdown overwrites the earlier explicit SDK snapshot after the Worker object is released. A regression control reproduces this overwrite with the old observer and passes with a first-terminal-per-activity guard. The failed profile remains retained; it is not promoted to complete.

A later PHP shutdown flush can overwrite the explicit SDK snapshot after Worker destruction. Treat final live bytes as shutdown process state, not retained Worker ownership. Scan rows and recorded peaks are unchanged. The roughly 520-to-216-MiB drop across finalization must therefore not be attributed to a particular analysis request or to GC alone.

Valid before/after afterAnalysis checkpoints show 9,826 cached class metadata records and no eager syntax, node-analysis or expression-type records in the final 531-file project summary. The final project-summary payload is 50,715 bytes. At most two top-level requests overlap. The largest payload is the earlier codebase-scan request, 35,763,346 bytes. Type-comparison cache keys total only 24,931 bytes. These measurements rule out the final project summary and comparison-key strings as explanations for the whole later heap increase.

Source inspection identifies StringHelperProvider searching for the one anonymous class inside the audited str helper by loading metadata for the complete class-name list. A separate private candidate first projects declared __call locations and then applies the original class-order and complete source/shape checks; it is not part of the admitted implementation or ordinary measurements in this report.
