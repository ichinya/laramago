# Optimization check: anonymous Laravel case 01

Checked on 16 October 2026. **Neither tested optimization was faster than Larastan on this workload.** The best completed Laramago pilot took 555.30 seconds; PHPStan/Larastan at level 10 with configuration ignores cleared took 93.11 seconds. These are single observations, not a repeated speedup estimate. No production analyzer implementation was changed.

The later [per-handler CPU study](cpu-profile-and-optimization-2026-10-16.md) records a separate argument-filter optimization, complete diagnostic-multiset controls and its own before/after measurements. The observations below remain the historical cache/reader experiments.

## Depth reference

The [existing depth matrix](analyzer-depth-and-timing-2026-10-16.md) covers 33 independently marked witness/control pairs, PHPStan levels 0–10, a separate Larastan config-inference control, and Mago with/without Laramago. Its 924 observations include 23 identifier-specific PHPStan level oracles. Laramago reports the selected target diagnostic on 29 of the 33 examples, with no findings on their marked corrected controls. This is not a whole-program false-positive estimate.

Coverage includes the selected union, nullable, explicit-mixed and implicit-mixed witnesses associated with PHPStan levels 7–10. In this `analyze` configuration, the selected impossible-type comparison and three missing-annotation examples have no Laramago target finding. Linter/preset alternatives were not tested. Error, Warning and Help remain distinct; this matrix does not assign Laramago a single equivalent PHPStan level.

Both optimization workers were freshly checked against the same public corpus: 132 Mago observations per worker, **264 fresh observations in total**, exactly matching the release matrix. This follow-up reuses the archived PHPStan level reference; it does not claim another execution of every PHPStan level.

## Bounded optimization pilots

The same private 531-file, 102,378-line workload was used. Its private input seal matches the earlier experiment, and all 590 installed Laramago `src`/`bin` files match the release after LF/CRLF normalization. Versions remain PHP 8.5.4, Mago 1.48.1, Laramago 0.1.5, PHPStan 2.2.13, Larastan 3.12.0 and Laravel 13.31.0.

Each profile ran sequentially in a fresh pinned Linux container with networking disabled, a two-CPU quota, a 6 GiB memory limit and read-only vendor. PHP children had a 2G limit and OPcache/JIT disabled; Mago used two threads/workers and PHPStan at most two processes. PHPStan used a fresh analysis cache separate from the configuration probe, level 10 and zero configuration ignores. Source annotations and other project settings remained effective.

| Variant | Status / N | Wall, s | CPU, s | Container peak, MiB | Diagnostics |
| --- | --- | ---: | ---: | ---: | ---: |
| Published Laramago, 64 MiB tree cache | Timed out / 1 | 1200 s deadline | Incomplete accounting | 1976.41 | No complete report |
| Tree cache 192 MiB + shared syntax in guarded reads | Completed pilot / 1 | 834.93 | 1142.49 | 2749.59 | 13196 |
| PHPStan + Larastan, level 10 | Completed pilot / 1 | 93.11 | 156.54 | 1202.09 | 8371 |
| Mago without extensions | Completed pilot / 1 | 10.81 | 10.51 | 827.86 | 21519 |
| Tree cache 192 MiB, guarded reader unchanged | Completed pilot / 1 | 555.30 | 838.81 | 2781.98 | 13196 |

Rows are in execution order. The failed release run stopped after approximately 1207.96 seconds including termination overhead; that is not a successful analysis time. Its CPU accounting excludes unreaped descendants and cannot be compared with completed CPU totals. The whole-command deadline was 1200 seconds here, versus 3600 seconds in the earlier repeated series. The extension request deadline remained 600,000 ms.

The planned repeated comparison stopped on the release failure and retained that receipt. A separate pilot collection then tested the two candidates, Larastan and native Mago. **There are no new comparative medians, confidence intervals or accepted speedup claims.** OS caches, VM/workstation background load and CPU frequency were not exclusive or controlled. Historical medians (415.83 s Laramago, 69.37 s PHPStan/Larastan level 10, 7.77 s native Mago, N=3 each) remain separate and unchanged.

## What the optimization tests establish

The first candidate increases only the bounded serialized tree-cache capacity from 64 to 192 MiB. The second also replaces the guarded reader's fresh parser/name-resolver call with the existing exact-byte syntax cache. Disk reads, physical-root checks, size limits, analyzed-snapshot checks, parent reconstruction and later source checks remain in place. The query-cache limits and native metadata decisions are unchanged. Composer class maps select copies outside vendor, and reflection verifies those copies were loaded.

Both candidates preserve the complete diagnostic multiset relative to each other on the private workload. Their diagnostic counts, code totals and severity totals also match the archived release output. **Full multiset equivalence to the current release run remains unverified**, because that run did not finish. Neither candidate is adopted as a production change.

Each guarded reader passed nine disk/syntax controls: unchanged reads, consumer isolation, same-size disk mutation, changed syntax, parse failure, oversized input, null/outside-root paths, restoration and deletion. Thirteen protocol tests passed, including malformed JSON receipt preservation, resume rejection, input-set changes and diagnostic-seal checks. These protocol controls are not analyzer acceptance evidence.

The 192 MiB-only candidate had the best pilot time, but still took about 5.96 times the Larastan time. Adding shared syntax to guarded reads had a worse pilot time and higher CPU cost than the capacity-only candidate. This does not establish a general regression from syntax reuse, but the earlier one-file microbenchmark is insufficient justification for shipping it.

## Next optimization work

1. Add separate, aggregate-only per-hook profiling: CPU, reads/bytes, parser calls, tree traversals, metadata RPC counts and RPC wait windows. Attribute inclusive versus exclusive time and overlapping worker intervals explicitly. Keep instrumented runs out of timing samples.
2. Measure the capacity/locality tradeoff on the complete workload. The earlier instrumented worker had 13,169 tree-cache evictions and 933 hits in 14,287 calls, with 131.86 MiB of unique serialized payloads against a 64 MiB budget. That identifies an eviction problem but does not measure its fraction of total runtime.
3. Prefer bounded, source-local pure extraction indices to repeatedly reconstructing entire mutable trees. Share immutable syntax facts across handlers while keeping current disk checks, native metadata, lifecycle state and final issue decisions outside these caches.
4. Validate the selected change with a completed release baseline, full residual diagnostic multisets, unchanged witnesses and at least three independently measured, counterbalanced rounds. Compare CPU and memory as well as wall time. Increasing a cache alone has not met the goal of beating Larastan.

## Reproduction

The [optimization controller](../../scripts/benchmark-optimization.py) generates verified overlays outside read-only vendor. Its default mode requires a completed release observation and full diagnostic equivalence before collecting balanced samples; failures stop and remain recorded. Use a new output directory for every attempt.

```text
python scripts/benchmark-optimization.py --volume DISPOSABLE_CASE_VOLUME --output-dir NEW_DIRECTORY
python scripts/benchmark-optimization.py --volume DISPOSABLE_CASE_VOLUME --output-dir NEW_PILOT_DIRECTORY --pilot-only --variants cache192-reader phpstan10 native cache192 --timeout 1200
LARAMAGO_BENCHMARK_LINUX_TESTS=1 python tests/benchmark-protocol.py
php tests/source-reader-cache.php --autoload=EXPERIMENT_AUTOLOAD.php
```

Pilot-only mode explicitly leaves the comparative study incomplete and creates independent diagnostic seals per variant. It cannot establish release equivalence by creating a replacement seal. The private workload cannot be independently rerun from the public repository. The public witnesses and protocol can be reproduced; timing another application produces a different case.

See the [aggregate observations](optimization-check-2026-10-16.json). Private source identifiers, messages and diagnostic fingerprints are excluded.
