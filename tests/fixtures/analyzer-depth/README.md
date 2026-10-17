# Analyzer-depth witnesses

`cases.php` contains 33 independently marked error/control pairs. The harness
creates a minimal Laravel application with model autoloading, an invented
migration, SQLite `:memory:` configuration and read-only dependencies. Function
and migration bodies are not invoked by the harness. Larastan boots this minimal
application normally, and an explicit reflection control verifies model loading.

The local `.gitattributes` preserves exact corpus/oracle bytes so Git newline
normalization cannot invalidate the recorded execution hashes.

Each `DEPTH id bad|good` comment identifies the source line to inspect. The PHPDoc
witness also includes the immediately following declaration line. Findings
elsewhere are not attributed to that target; these controls are not an exhaustive
whole-file false-positive assessment. PHPStan identifiers, Mago codes and severity
are retained so that incidental diagnostics cannot establish an expected fault.

`expectations.json` defines 23 identifier-specific PHPStan thresholds spanning
levels 0 through 10. The result validator checks the first matching level,
cumulative detection above it, clean corrected core controls and exact corpus/
harness hashes. Other witnesses explore generics and Laravel; their observed
thresholds and control findings remain data rather than success requirements.

Mago is tested in `analyze` mode, with and without the complete stock Laramago
worker. Linter rules are outside this matrix. Error, Warning and Help are distinct:
an advisory observation must not be presented as a CI-blocking diagnostic.

The primary PHPStan matrix enables Larastan's `checkConfigTypes` explicitly.
A separate level-10 run retains its default `false` value, exposing configuration
effects separately from level effects. This fixture policy differs from the
private timing project's retained framework settings and is recorded in JSON.

Run `scripts/benchmark-depth.php` in a disposable Linux container with the pinned
dependency versions from the report, a read-only vendor mount under the fixture
root, and a separately mounted Mago executable. Then run:

```text
python tests/analyzer-depth-results.py --result depth.json --summary depth-summary.json
```

The matrix establishes behavior on these public examples. It does not assign
Laramago a universal PHPStan level or establish production false-positive rates.
