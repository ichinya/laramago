# Anonymous Laravel application benchmark: case 01

Measured on 15 October 2026 using a real application and the published Laramago
0.1.5 release. **Laramago did not complete this workload with either the default
30-second extension-request deadline or an increased 120-second deadline.**
PHPStan/Larastan completed the configured analysis. The failed Laramago elapsed
times are times until termination, not faster completed analyses or zero-error
results.

The application's name, repository, source, file identifiers, diagnostic messages
and business domain are not published. This is one case on one workstation,
not a general performance or correctness ranking.

## Environment and scope

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

## Measured full-run observations

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

## Laramago completion limitation

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

## References

- [Laramago 0.1.5 release](https://github.com/ichinya/laramago/releases/tag/0.1.5).
- [PHPStan result cache](https://phpstan.org/user-guide/result-cache), [CLI](https://phpstan.org/user-guide/command-line-usage), [configuration](https://phpstan.org/config-reference).
- [Windows job memory counters](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-jobobject_extended_limit_information).
