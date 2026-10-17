# Analyzer depth and repeated timings: case 01

Measured on 16 October 2026. This supplements the [original observations](anonymous-laravel-case-01-2026-10-15.md), which remain unchanged. The public witness corpus measures specific detection behavior; the private application supplies a separate timing workload. No single PHPStan level is assigned to Laramago.

## Detection matrix

The [public corpus](../../tests/fixtures/analyzer-depth/cases.php) has 33 defect/control pairs. All eleven PHPStan levels and native Mago with/without Laramago were actually executed: 924 marked observations across 14 configurations. Twenty-three [identifier-specific level oracles](../../tests/fixtures/analyzer-depth/expectations.json) verify the selected PHPStan thresholds and corrected controls. An unrelated diagnostic cannot establish the expected fault. Other examples cover generics and Laravel. The source and harness hashes and installed versions are recorded in JSON.

The fixture is a minimal Laravel application with read-only installed dependencies, normal model autoloading, an in-memory SQLite configuration and an invented migration. Application secrets and the original application source are not needed for these cases. Fixture functions and migration bodies are not invoked by the harness. Larastan boots the minimal application normally. The primary matrix enables Larastan checkConfigTypes so config inference is exercised. A separate level-10 control keeps its default false value. The private timing application retains its original framework settings; the profiles therefore do not have globally identical checks.

Each cell lists findings at the marked source position. E/W/H mean Error/Warning/Help; Help is advisory and must not be counted as a CI-blocking Error. A dash means no matching target finding in this configuration. Controls are marked positions, not an exhaustive whole-program false-positive estimate.

| Witness | First PHPStan level | Laramago target finding | Flagged corrected control |
| --- | ---: | --- | --- |
| unknown-function | 0 | non-existent-function/Error | — |
| unknown-class | 0 | non-existent-class/Error | — |
| undefined-variable | 0 | undefined-variable/Error | — |
| missing-argument | 0 | too-few-arguments/Error | — |
| maybe-undefined | 1 | possibly-undefined-variable/Warning | — |
| magic-property | 2 | non-documented-property/Warning | — |
| unknown-method | 2 | non-existent-method/Error | — |
| incompatible-phpdoc | 2 | docblock-type-mismatch/Error | — |
| return-type | 3 | invalid-return-statement/Error | — |
| property-type | 3 | invalid-property-assignment-value/Error | — |
| unreachable | 4 | unevaluated-code/Help | — |
| impossible-type | 4 | — | — |
| argument-type | 5 | invalid-argument/Error | — |
| missing-parameter | 6 | — | — |
| missing-return | 6 | — | — |
| iterable-value | 6 | — | — |
| union-argument | 7 | possibly-invalid-argument/Error | — |
| union-method | 7 | non-existent-method/Error | — |
| nullable-method | 8 | possible-method-access-on-null/Error | — |
| nullable-property | 8 | possibly-null-property-access/Error | — |
| explicit-mixed-argument | 9 | mixed-argument/Error | — |
| explicit-mixed-method | 9 | mixed-method-access/Error | — |
| implicit-mixed-argument | 10 | mixed-argument/Error | — |
| implicit-mixed-method | 10 | mixed-method-access/Error | — |
| array-shape | 3 | invalid-return-statement/Error | — |
| callable-argument | 5 | invalid-argument/Error | — |
| generic-identity | 3 | invalid-return-statement/Error | — |
| model-property | 3 | invalid-return-statement/Error | — |
| collection-first | 3 | invalid-return-statement/Error | — |
| config-value | 3 | invalid-return-statement/Error | — |
| query-nullable | 8 | possibly-null-property-access/Error | — |
| migrated-property | 3 | invalid-return-statement/Error | — |
| config-default | 3 | invalid-return-statement/Error | — |

All checks here use analyze mode; missing-annotation linter/preset alternatives need their own matrix. The matrix includes high-level mixed-type checks alongside gaps in missing-annotation checks and a different treatment of dead code. This is overlapping coverage across levels, not a numeric equivalence. The complete native-Mago comparison and PHPStan control findings are retained in JSON, including unresolved/conservative Laravel results. Differences on one example are not universal claims about a framework feature.

## Timing protocol

The same frozen 531-file application and published Laramago release were used. Each profile had a fresh network-disabled Linux container, two CPUs, a 6 GiB container limit, PHP 8.5.4 with OPcache/JIT off and 2G per PHP process, two Mago threads/workers or at most two PHPStan analysis processes. Dependencies were mounted read-only. The image digest and runner hash are pinned in JSON. Source inputs, configuration, migrations, Composer files and bootstrap entry points were sealed privately and checked for changes; private fingerprints are not exported.

One full warmup per tool was excluded. Three measured rounds rotate the three profiles so that every tool occupies every position once. Only one case-01 analyzer ran at a time. PHPStan uses level 10 and zero configuration ignore patterns; source-level annotations, stubs and other project settings remain in effect. The configuration probe has a separate cache. The measured tmpDir is entirely absent before startup, and a full result-cache miss is confirmed from verbose output. OS disk caches are not flushed; these are warm-disk full analyses, not incremental-cache or cold-disk runs.

Only completed reports contribute to medians. Diagnostic/code/severity totals must stay identical across repeats; timeout, transport failure, changed source and incomplete output stop the suite and preserve a failure receipt. Protocol tests also exercise these rejection boundaries. An early setup trial was canceled before measured samples after identifying the configuration-probe cache asymmetry; it is excluded from this series.

| Profile | N | Wall median, s | Wall min–max, s | CPU median, s | Container peak median, MiB | Diagnostics |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Mago + Laramago 0.1.5 | 3 | 415.83 | 405.72–524.25 | 679.94 | 2098.89 | 13196 |
| PHPStan + Larastan, level 10, config ignores reset | 3 | 69.37 | 54.99–73.67 | 111.89 | 1181.91 | 8371 |
| Mago without extensions | 3 | 7.77 | 7.60–7.78 | 5.06 | 816.31 | 21519 |

Wall/CPU timing covers the analyzer command, after configuration preparation. CPU is reaped child CPU; successful runs include extension workers. Largest-child RSS is not a simultaneous process-tree peak. Cgroup memory includes the runner, configuration probes, children and charged file cache; it is not PHP allocator usage or analysis-only RSS. Background VM/workstation load and CPU frequency are not exclusive or controlled. With N=3 on one workload, ranges and medians are descriptive; no population confidence interval or general speed ranking is claimed.

## Optimization evidence

The [optimization follow-up](optimization-check-2026-10-16.md) records subsequent bounded cache/reader pilots, their correctness controls and an incomplete release baseline. Its single observations are separate from the repeated timings below.

The later [per-handler CPU study](cpu-profile-and-optimization-2026-10-16.md) measures individual callbacks, validates a conservative argument-filter shortcut and has a separate before/after timing series. It retains the archived depth matrix as the PHPStan reference and checks the candidate Mago matrices independently.

A separate diagnostic control wraps the release worker with passive first/last phase hooks and a metrics-only copy of its syntax cache outside vendor. Cache limits and decisions are unchanged; the benchmark samples use uninstrumented release code. It preserves the completed diagnostic count, but its timings are not mixed into the benchmark samples. PHP CPU is measured within each worker; wall intervals across workers overlap.

| Phase | Worker | Wall interval, s | PHP CPU, s |
| --- | ---: | ---: | ---: |
| scan | 10 | 115.10 | 71.02 |
| before-analysis | 10 | 0.67 | 0.65 |
| scan | 13 | 114.05 | 69.65 |
| after-analysis | 13 | 0.00 | 0.00 |

The existing syntax cache stores up to 64 MiB of serialized trees and 1024 entries; its pure-query cache stores up to 8 MiB and 8192 entries. These serialized payload limits are not resident-memory limits. Metrics count lookups and LRU evictions without exporting source keys.

| Worker | Cache | Calls | Hits | Misses | Evictions | Unique payload total, MiB | Peak retained serialized, MiB |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 13 | trees | 14287 | 933 | 13354 | 13169 | 131.86 | 64.00 |
| 13 | queries | 3717 | 2124 | 1593 | 0 | 0.97 | 0.97 |

Terminal cache counters were captured for one of the two initialized workers. This is a partial worker observation, not a pool-wide hit rate. Some lifecycle phase boundaries were dispatched to different workers, so no cross-worker analysis interval or exhaustive CPU partition is inferred. The recorded worker had a serialized working set larger than the tree-cache capacity and substantial eviction traffic. This identifies a measured cache-locality problem, without assigning it a percentage of total runtime.

The source-reader experiment compares the existing fresh-parse implementation with a prototype using the existing bounded syntax cache. Both still read current disk bytes, enforce root/size/snapshot checks and reconstruct parent relationships. Returned ASTs remain isolated between consumers. No native metadata or final issue decision is cached. Four samples per reader alternate order after excluded warmups.

| Source reader | Reads/sample | Median, s | Min–max, s |
| --- | ---: | ---: | ---: |
| prototype-shared-syntax | 1000 | 0.9839 | 0.8393–1.2375 |
| stock-fresh-parse | 1000 | 2.6605 | 2.3521–4.4960 |

This microbenchmark uses one public file. Its ratio is not an end-to-end speedup. Controls verify equal resolved syntax, consumer isolation, changed-disk veto, changed-byte invalidation, root/size rejection, parse failure and restoration. No production analyzer implementation was modified.

Priorities for a subsequent optimization change:

1. Address the measured tree-cache capacity/locality mismatch. Evaluate bounded capacities such as 160/192/256 MiB and source-local scan dispatch under the existing 2G worker limit. Compare hit rate, CPU and peak memory before choosing a limit. The recorded pure-query cache has no evictions and uses less than 1 MiB of its 8 MiB budget.
2. Reuse exact-byte resolved syntax in repeated contract reads while preserving fresh physical-source, root and post-RPC checks. The source-reader prototype supports this local optimization, but its full-workload benefit remains unmeasured.
3. Reduce repeated full-tree cloning/traversal with bounded, policy-specific pure extraction indices. Keep native metadata, worker lifecycle, runtime configuration and final suppression decisions outside syntax caches. Profile metadata RPC before proposing batching changes.
4. Repeat the frozen before/after benchmark and require complete residual diagnostic multisets and unchanged depth witnesses. Add cache-eviction, mutation-isolation, source-change, SDK-metadata mutation, reentrancy and cancellation controls; compare CPU and memory as well as wall time.

## Reproduction and checks

[`benchmark-suite.py`](../../scripts/benchmark-suite.py) runs the counterbalanced private-application series; `--runs` must be a multiple of three, with at least three samples. [`benchmark-depth.php`](../../scripts/benchmark-depth.php) creates the disposable public fixture application. [`benchmark-phases.py`](../../scripts/benchmark-phases.py) and [`profile-source-reading.php`](../../scripts/profile-source-reading.php) are separate diagnostic experiments.

```text
python tests/benchmark-protocol.py
LARAMAGO_BENCHMARK_LINUX_TESTS=1 python tests/benchmark-protocol.py
python tests/analyzer-depth-results.py --result depth.json --summary depth-summary.json
python scripts/benchmark-suite.py --volume DISPOSABLE_CASE_VOLUME --output timings.json --runs 3
python scripts/benchmark-phases.py --volume DISPOSABLE_CASE_VOLUME --output-dir NEW_PROBE_DIRECTORY
```

The optional Docker controls run the real Linux runner against a synthetic analyzer, testing additions, removals, renames and edits of workload inputs. They are protocol tests, not analyzer acceptance or timing samples. The runner now re-enumerates the complete selected input set after analysis; malformed or non-object JSON is retained as a failure and cannot be resumed. Historical measurements and their runner hashes remain unchanged. A changed runner requires a new timing series.

Run the fixture harness in a disposable Linux container. Mount the installed dependencies read-only at `/fixture/vendor`, set `/fixture` as the working directory and use `--dependencies=/fixture --root=/fixture`; mount the pinned Mago binary separately. Keep the fixture app and output directory writable, and provide the pinned dependency versions listed in JSON. The full application remains private, so external readers can reproduce the method and the public corpus, not independently rerun the exact private workload.

References: [PHPStan levels](https://phpstan.org/user-guide/rule-levels), [result cache](https://phpstan.org/user-guide/result-cache), [configuration](https://phpstan.org/config-reference), [pinned Larastan feature defaults](https://github.com/larastan/larastan/blob/ab26fdf90d84877061388109e1e251f67ca3ff7a/extension.neon), [aggregate data](analyzer-depth-and-timing-2026-10-16.json).
