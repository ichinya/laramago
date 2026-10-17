# Anonymous Laravel application benchmark: case 01

Measured on 15 October 2026 using a real application and the published Laramago
0.1.5 release. **The full Laramago analysis completed in an isolated Linux control.**
Windows attempts remain unsuccessful: the 30/120-second request deadlines were
insufficient, and a 600-second diagnostic repeat encountered a worker transport
I/O error with Windows status 10053. Completed Linux observations and unsuccessful
Windows attempts are reported separately; no cross-platform speedup is claimed.

The application's name, repository, source, file identifiers, diagnostic messages
and business domain are not published. This is one case on one workstation,
not a general performance or correctness ranking.

## Completed Linux control

The same disposable application copy, PHP 8.5.4, dependency versions, selected
roots and Laramago release were used in a network-disabled Linux/amd64 container
on the same workstation. The official PHP image is pinned by digest in JSON.
The source copy was prepared in a Docker Linux volume before timing. Each
profile ran sequentially in a fresh container with a two-CPU quota and a 6 GiB container
memory limit; PHP children retained the inherited 2G limit and OPcache/JIT-off
settings. Mago used two analysis threads and two extension workers; PHPStan
was limited to two analyzer processes. The Mago extension request deadline was 600,000 ms. Each analyzer had a
3,600-second whole-command deadline. The container was
removed between profiles. Instrumented diagnostic runs ended before these
sequential case-01 profiles; the measured extension worker was unmodified.
The source configuration is standalone and extends only Laramago's Laravel
preset. Dependency metadata is selected from Composer's generated PSR-4, PSR-0,
classmap and autoload-files maps. The original root config is deliberately not
inherited: Mago appends inherited source lists, so that config reintroduced a
blanket `vendor` include. The effective Mago config was checked to retain exactly
the four application roots and no blanket `vendor` include. No dependency source
was modified for these completed controls. A nested read-only vendor mount
was checked by the runner before each timed profile; only benchmark configs and
cache locations outside vendor were writable.
Each row is one observation. Workstation background load and OS caches were
not controlled.

| Linux profile | Wall time, s | CPU time, s | Largest child RSS, MiB | Container peak, MiB | Exit | Diagnostics |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Mago + Laramago 0.1.5 | 388.04 | 668.28 | 868.91 | 2208.29 | 1 | 13196 |
| PHPStan + Larastan, configured level 5 | 62.93 | 111.05 | 497.96 | 1208.93 | 0 | 0 |
| Mago, no extensions | 7.89 | 5.21 | 839.31 | 841.88 | 1 | 21519 |

Largest child RSS is Linux `RUSAGE_CHILDREN.ru_maxrss`, the largest individual
child peak; it is not the sum or simultaneous peak of the process tree. Container
peak is cgroup v2 `memory.peak`, which includes the runner, all container
processes and charged file cache. It is read before JSON diagnostic aggregation.
These counters are different from Windows Job Object committed memory and must
not be compared as if they measured the same quantity.

| Completed Linux profile | Severity counts |
| --- | --- |
| Mago + Laramago 0.1.5 | Error: 10393, Help: 124, Warning: 2679 |
| PHPStan + Larastan, configured level 5 | No diagnostics |
| Mago, no extensions | Error: 13229, Help: 187, Warning: 8103 |

Exit 1 denotes completed analysis with diagnostics, rather than an infrastructure
failure. The complete JSON issue report was parsed, severity/code totals were
collected, and dependency-parse and extension-failure markers were rejected.
Counts are observations, not a triage of true/false positives. Full messages,
source paths and source excerpts are not exported.

The completed control changes the environment, dependency selection and
parallelism. Single-thread Linux runs with the original full-vendor include and
the corrected Composer-autoload include both reached a 1,200-second whole-command
deadline without a complete report. Disabling PHP cycle collection also produced
no complete report within a shorter 300-second diagnostic trial. Those are
separate unsuccessful controls in JSON. Their child CPU accounting is incomplete
after forced termination, so it must not be used as total worker CPU time.

These controls cannot isolate any one of these differences as the sole cause of the earlier failures. Mago 1.48.1
uses a localhost TCP input on Windows and process stdin on other platforms
([pinned upstream worker source](https://github.com/carthage-software/mago/blob/1.48.1/crates/extension/src/worker.rs)).
This is a successful configuration/environment control, not a demonstrated fix of the Windows
failure or proof of its underlying cause. PHP's native-stack checking also used
the image default (`0`); the Windows installation had it disabled (`-1`). Neither
the Laramago implementation nor the application source was patched.

## Windows environment and shared source scope

- Windows 11, build 10.0.26300; Intel Core i9-11900K, 8 physical / 16 logical cores; 63.9 GiB RAM.
- PHP 8.5.4; CLI OPcache/JIT disabled for all PHP children using an inherited INI scan directory.
- PHPStan 2.2.13, Larastan v3.12.0, Laravel v13.31.0.
- Mago 1.48.1; Laramago 0.1.5, commit `1a96f889fcd0d382079da11c500179bffc5d5ea2`.
- 531 selected PHP files, 102,378 physical lines, 4,288,724 bytes.
- Same selected application roots: `app`, `tests`, `scripts/testing`, `testo.php`.
- One analysis worker at a time per tool; helper/child processes remain included in accounting.

The committed source was exported to a disposable local copy. Locked dependencies
were installed there with `ichinya/laramago:0.1.5` as an additional development
dependency. All 590 installed Laramago `src`/`bin` files match the release checkout
after LF/CRLF normalization. No application code was edited to improve a result;
the original checkout and its configuration were not modified.

A synthetic testing environment used an in-memory database target and array-backed
cache/session services. No production database or application secrets were copied.
Laramago runtime configuration evaluation and runtime schema collection were
disabled. Larastan boots Laravel in its normal analysis; default Laramago analysis
loads the Composer autoloader and reads source metadata.

## Original Windows full-run observations

These rows each contain **one** full-run observation, not repeated estimates.
PHPStan used a new `tmpDir` for each full profile. The OS file cache was not
flushed, so these are not cold-disk measurements. The separate warm-cache series
below has three observations. Background workstation load and CPU frequency
were not controlled.

| Profile | Wall time, s | Peak job commit, MiB | CPU time, s | Exit | Diagnostics / outcome |
| --- | ---: | ---: | ---: | ---: | --- |
| PHPStan + Larastan, level 5, configured ignores | 284.15 | 577.70 | 218.94 | 0 | 0 |
| PHPStan + Larastan, level 5, config ignores cleared | 316.31 | 578.11 | 253.53 | 1 | 54 |
| PHPStan + Larastan, max, config ignores cleared | 525.00 | 574.09 | 288.95 | 1 | 8371 |
| Mago with Laravel preset, no extensions | 36.98 | 1145.16 | 21.42 | 1 | 21242 |
| Mago + Laramago 0.1.5, 120 s request deadline | 147.45 | 1680.23 | 93.42 | 2 | Incomplete: extension-request-timeout |

Memory is Windows Job Object `PeakJobMemoryUsed`: the process tree's peak
committed memory, not RSS and not PHP's allocator-only peak. The root process
is attached immediately after spawn; descendants inherit the job. The OS tracks
the peak continuously, rather than summing separately sampled process maxima.
Process counts and per-run counters are in the aggregate JSON.

The diagnostic controls distinguish the project's existing PHPStan suppressions
from additional diagnostics at stricter settings. Their levels and checks are
different from Mago's. Diagnostic counts do not prove correctness or classify
findings as true/false positives.

## Windows Laramago completion failures

The default-host attempt failed on extension request 3 after the 30,000 ms
request deadline. It terminated after **50.42 s**, reaching **1,412.73 MiB** peak
job commit, and produced no complete diagnostic report. A deliberately invalid
Laravel dependency fixture also caused a parsing warning in that first attempt.

The next attempt used `request-timeout-ms = 120000` and point-excluded the
invalid dependency fixture. It still exited with infrastructure status 2 after
**133.75 s**, reaching **1,694.20 MiB**. Its raw diagnostic stream was not retained;
this attempt is labelled by its observed exit status. The measured 120-second
control in the table independently confirms the extension-request timeout.

These attempts are retained under `setup_probes` in JSON. Timeout or incomplete
analysis must not be reported as a successful fast run or an error-free project.
The whole-command safety deadline is 900 seconds; the extension request deadline
is a separate limit. This case identifies a completion/performance boundary to
investigate in Laramago 0.1.5 on a larger application.

A further unmodified-release attempt increased the extension request deadline
to 600,000 ms. It exited 2 after **419.02 s**, with
**1793.84 MiB** peak job commit. Its raw stderr was not
retained, so its recorded classification remains infrastructure failure.

A diagnostic repeat with a transparent worker-process proxy exited 2 after
**476.47 s**, with **1770.28 MiB**
peak job commit. It explicitly reported extension protocol I/O failure and
Windows error **10053** (connection aborted by the host). It produced no complete
issue report. The proxy adds processes and is a diagnostic control, not a
performance replacement for the original run. No antivirus or firewall settings
were changed. These later attempts are retained in `completion_controls`.

## PHPStan incremental result-cache observations

The configured level-5 command and its populated cache were reused three times
without changing application source. Verbose PHPStan output was checked for its
result-cache-used message. This is a different workload from a full analysis.

| Run | Wall time, s | Peak job commit, MiB | Diagnostics | Result cache confirmed |
| --- | ---: | ---: | ---: | --- |
| 1 | 2.15 | 128.28 | 0 | true |
| 2 | 2.16 | 128.28 | 0 | true |
| 3 | 2.06 | 128.30 | 0 | true |

No persistent cross-process Mago result cache was configured. Laramago's source
query cache is scoped to an analyzer run. This report does not compute a speedup
ratio between the incomplete Laramago run and successful PHPStan runs.

## Configuration and diagnostic boundaries

The configured PHPStan profile uses level 5 and seven existing `ignoreErrors`
patterns. The unfiltered controls clear configuration-level `ignoreErrors`; source
annotations, installed stubs, exclusions and PHPDoc settings remain effective.
`treatPhpDocTypesAsCertain` and `rememberPossiblyImpureFunctionValues` remain false.
The max control additionally sets `--level=max`.

Both Mago profiles inherit the same Laravel preset; the native reference uses
`--no-extensions`. The preset disables unused-definition, unused-parameter,
missing-type-hint, missing-override and throws checks while retaining expression,
nullability and concrete-type checks. Its analyzer ignore-code list is empty.

The dependency-only invalid fixture
`vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php` is
point-excluded in the measured controls. The application's pre-existing point
exclusion for an unresolved Testo generator template is retained. Neither removes
any of the 531 selected application PHP files. Dependency inclusion, built-in
stubs, severities and type contracts still differ between the tools.

| Completed profile | Severity counts |
| --- | --- |
| PHPStan + Larastan, level 5, configured ignores | Error: 0 |
| PHPStan + Larastan, level 5, config ignores cleared | Error: 54 |
| PHPStan + Larastan, max, config ignores cleared | Error: 8371 |
| Mago with Laravel preset, no extensions | Error: 11105, Help: 182, Warning: 9955 |

See the [aggregate JSON](anonymous-laravel-case-01-2026-10-15.json) for all code counts, CPU times,
process counts, memory peaks and explicitly marked failed attempts. It contains
no application-specific paths, messages or source excerpts.

## Reproduction

The aggregate-only Windows runner is
[`scripts/benchmark-anonymous.py`](../../scripts/benchmark-anonymous.py).
It requires Python 3.10+, PHP, installed PHPStan/Larastan dependencies and
`ichinya/laramago:0.1.5`. It writes benchmark-only configuration and cache files
inside the supplied application copy. Use a disposable copy with a synthetic
environment and no production secrets.

```powershell
python scripts/benchmark-anonymous.py `
  --project C:/benchmarks/anonymous-app `
  --php C:/php/php.exe `
  --output C:/benchmarks/results/measurements.json `
  --runs 1 --control-runs 1 --warm-runs 3 --timeout 900
```

`--resume` reuses completed observations only after verifying the tool environment
and source counts. For this case, preserve the documented roots, level, ignore
count, dependency versions, one-worker setting and INI controls. The source is
private, so an external reader can reproduce the method, not independently rerun
this exact workload from the repository. Label measurements on another workload
as another case.

A discarded preflight series exposed that PHP CLI `-d` settings alone did not
reach the PHPStan worker. Its next control was canceled. The documented series
uses inherited `PHP_INI_SCAN_DIR` overrides and a no-`-d` child-environment probe
to apply OPcache-off / 2G memory settings consistently. Preflight timings are not
mixed into the measured table. No analyzer implementation or application code
was patched as part of this benchmark.

## Reproducing the completion control

[`scripts/benchmark-anonymous-linux.php`](../../scripts/benchmark-anonymous-linux.php)
collects aggregate-only Linux results. Obtain the Mago 1.48.1 Linux/amd64 binary
from its official release. Use a disposable application copy with the same
locked dependencies and synthetic environment. Mount this script directory
read-only at `/runner` and the disposable application at `/case` in a fresh
container for each profile. Prepare the source in a Docker Linux volume before
timing; the canceled Windows bind-mount preflight is excluded. For the documented measurement, use the pinned PHP
image, `--network none --cpus 2 --memory 6g`. Add a nested read-only
mount of the same volume with `target=/case/vendor,volume-subpath=vendor,readonly`.
The runner rejects a writable vendor directory. Run:

```sh
php -d memory_limit=2G -d opcache.enable_cli=0 \
  /runner/benchmark-anonymous-linux.php \
  --project=/case --mago=/case/.benchmark-linux/mago \
  --output=/case/laramago-linux.json --profile=mago-laramago \
  --request-timeout-ms=600000 --timeout=3600 \
  --dependency-scope=composer-autoload --threads=2 --extension-workers=2 \
  --phpstan-processes=2 --php-gc=on
```

The other measured profiles are `phpstan-level5-configured` and `mago-native`.
Keep a separate output file and a fresh container for each. Do not reuse a
container's cgroup peak across profiles. The source is private; these commands
reproduce the method, not an independently available workload.

The Windows runner also accepts `--profiles mago-laramago` and
`--extension-request-timeout-ms 600000 --timeout 1200` for a targeted repeat.
Changing either deadline is rejected by `--resume`; use a separate output to
preserve prior unsuccessful observations.

## References

- [Laramago 0.1.5 release](https://github.com/ichinya/laramago/releases/tag/0.1.5).
- [PHPStan result cache](https://phpstan.org/user-guide/result-cache), [CLI](https://phpstan.org/user-guide/command-line-usage), [configuration](https://phpstan.org/config-reference).
- [Windows job memory counters](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-jobobject_extended_limit_information).
