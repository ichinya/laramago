# ichinya/laramago

A Composer package with a Laravel preset for the native Mago CLI.

```sh
composer config repositories.laramago vcs https://github.com/ichinya/laramago
composer require --dev ichinya/laramago:0.0.1
vendor/bin/mago lint
```

During installation, Composer asks for permission to run the `ichinya/laramago`
plugin. Once allowed, the plugin creates `mago.dist.json` in the application root.
The `carthage-software/mago` dependency provides `vendor/bin/mago`; this package
uses that executable directly, without a wrapper or Laravel service provider.

Version `0.0.1` is the initial release. Until the package is registered on Packagist,
use the GitHub VCS repository shown above. For local package development, see the
path repository instructions below.

## How it works

The plugin runs on Composer's `post-install-cmd` and `post-update-cmd` events.
If the application has no Mago configuration, it creates a file such as:

```json
{
    "extends": "vendor/ichinya/laramago/presets/laravel.toml",
    "source": {
        "paths": ["app", "bootstrap", "config", "database", "routes", "tests"],
        "includes": ["vendor"]
    },
    "extension-hosts": {
        "laramago": {
            "command": ["php", "vendor/ichinya/laramago/bin/laramago-worker.php", "vendor/autoload.php"]
        }
    }
}
```

Only existing standard directories are added to `paths`. If none exist, the
plugin uses `.`. Add custom directories such as `src` or `Modules` yourself.
Dependency paths respect Composer's `vendor-dir` setting.

Mago loads `mago.dist.json` automatically and inherits the package settings through
`extends`. Updating the package updates the shared preset, while application
settings stay in the root configuration. Commit the generated configuration.
Set the application's PHP version with the root `php-version` option; the preset
does not pin it.

## Settings

- Laravel linter integration, optional `strict_types`, and support for `?:`.
- More permissive thresholds for method counts, parameter counts, and complexity.
- Formatting based on Mago's built-in Pint preset.
- Exclusions for caches, Blade templates, node_modules, and IDE helper files.
- Vendor dependencies available for type information, outside lint/format targets.
- Type errors and missing methods remain visible in the analyzer. Unused definition
  checks are disabled because Laravel often invokes definitions indirectly.

### Linter diagnostic levels

The default profile supports adoption in existing Laravel applications.
Recommendations stay visible without making every recommendation fail the command.
A successful lint run does not mean that every issue in the application is resolved.

| Rules | Level and rationale |
| --- | --- |
| `cyclomatic-complexity`, `kan-defect`, `halstead`, `too-many-methods`, `excessive-parameter-list`, `too-many-enum-cases` | Warning: design metrics require context |
| `final-controller`, `no-empty` | Warning: application style choices |
| `no-literal-password` | Warning: Mago 1.48.1 also flags `'password' => 'hashed'` in casts and `'token' => 'required'` in validation rules |
| `sensitive-parameter` | Warning: parameter name heuristics need review; the attribute remains useful for actual secrets |
| `no-error-control-operator` | Warning: review error handling after `@`, as well as the operator itself |
| `no-unsafe-finally` | Warning: Mago 1.48.1 also flags `continue` inside a loop contained in finally; actual return/throw statements still need review |
| `no-eval` | Error in application code; only `tests/**` is excluded to allow checks of generated PHP configuration |

Review security warnings: the default profile is not a standalone security gate.
Security checks remain enabled outside the documented exclusions.
Syntax errors still fail lint. To fail on warnings as well:

```sh
vendor/bin/mago lint --minimum-fail-level=warning
```

You can raise an individual rule's severity in the application configuration:

```toml
[linter.rules]
no-literal-password = { level = "error" }
```

```sh
vendor/bin/mago config --show linter
vendor/bin/mago list-files
vendor/bin/mago lint
vendor/bin/mago format --check
vendor/bin/mago analyze
```

Use `vendor/bin/mago.bat` in PowerShell. The analyzer extension currently supports
magic `where`, `orWhere`, `whereNot`, and `orWhereNot` calls on Eloquent models.
It reads signatures from the installed Laravel metadata and returns
`Builder<YourModel>`, including the builder passed to a where callback. Native
argument count, argument type, and named argument checks remain active.

Declared methods keep their native behavior. Models with custom query factories,
magic dispatchers, builder properties, or `UseEloquentBuilder` attributes are left
to Mago's existing analysis until their semantics are supported. Other magic
methods, model properties, relationships, scopes, and facades are still outside
this initial extension's coverage. The package does not generate overlays or
replace Larastan. `examples/compatibility.toml` is an optional fragment with
targeted suppressions for your `[analyzer]` section.

The worker uses Mago's bundled PHP SDK and the application's Composer autoloader.
It does not bootstrap Laravel or connect to a database. The `php` executable must
be on PATH; a project may override the worker command with a specific executable.

## Existing configuration

The plugin preserves any `mago.{toml,yaml,yml,json}` or
`mago.dist.{toml,yaml,yml,json}` file and prints the preset path.
For an existing TOML configuration, add this **at the top level, before any sections**:

```toml
extends = "vendor/ichinya/laramago/presets/laravel.toml"
```

For JSON, add an `extends` property; for YAML, add an `extends` key.
If the configuration already inherits other files, add the preset to its parent list.
For a custom `vendor-dir`, use the path printed by the plugin.
Keep your source paths and includes. Application settings override scalar values
from the preset; Mago concatenates arrays, so inherited exclusions remain active.

To enable analyzer support in an existing configuration, also add:

```toml
[extension-hosts.laramago]
command = ["php", "vendor/ichinya/laramago/bin/laramago-worker.php", "vendor/autoload.php"]
```

For JSON or YAML, add the equivalent `extension-hosts` mapping. Use the worker and
autoload paths printed by the plugin when the package or vendor directory is
elsewhere. Existing configurations are preserved during updates, so applications
installed before the analyzer extension was added need this one-time setup.

Use this manual setup when automatic configuration creation is skipped, such as
when Composer runs with `--no-plugins`.

Removing the package leaves the application configuration in place. Remove its
`extends` reference and `extension-hosts.laramago` entry, or replace them with your
own settings before running Mago again.

## Local development installation

Add a path repository to the test Laravel application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "C:/projects/laramago",
            "options": {"symlink": false}
        }
    ]
}
```

Then run:

```sh
composer config allow-plugins.ichinya/laramago true
composer require --dev ichinya/laramago:@dev
vendor/bin/mago lint
```

Use `symlink: false` to test a regular package copy inside vendor.
For CI, store plugin permission in the application's `config.allow-plugins`.
GitHub releases do not automatically register the package on Packagist.

## Development

### Comparing with Larastan

Larastan is an independent reference for Laravel behavior. Its code and PHPStan
extensions are not copied into this package. Our providers use Mago's native PHP
extension SDK; comparison results guide their development and verification.

```sh
php scripts/compare.php --project=C:/projects/laravel-app
```

The test application must have Mago and Larastan installed, a `phpstan.neon`
configuration (or a dist variant), and a working Laravel bootstrap. The script
runs the tools through the current PHP executable using the application's
dependencies. It leaves application configuration, source files, and baselines
unchanged. Analyzers may write their own caches; Larastan boots the Laravel container.

The runs execute sequentially:

1. Larastan with the application configuration.
2. Larastan at the same level, with configuration-level `ignoreErrors` cleared.
3. Larastan at the maximum level, with those suppressions cleared.
4. Mago on the source paths from the PHPStan configuration.
5. Both analyzers on `tests/fixtures/analysis/eloquent.php`: explicit `query()`,
   magic `where()`, a misspelled method, a missing argument, and an invalid argument.

Reports are stored in `var/comparisons/<project-name>/<UTC-timestamp>/`, which Git
ignores. Override the directory with `--output`. `comparison.md` contains the
overview; `summary.json` records versions, paths, levels, diagnostic codes, and
exit statuses. Raw JSON and normalized diagnostics are saved separately for each run.
`mago-only-location-candidates.json` lists Mago diagnostics on lines without a
Larastan max diagnostic. These are candidates for investigation: matching line
numbers do not establish equivalent meaning, and the absence of a Larastan
diagnostic does not prove a Mago false positive. Each tool's own exclusions and
source annotations remain in effect.

Bootstrap errors and internal PHPStan failures abort the comparison; an incomplete
run cannot count as a successful check with zero errors. On the first Windows run,
`php artisan package:discover` may be needed to prevent parallel Larastan processes
from attempting to create a missing package manifest at the same time.

### Package checks

PHP 8.2+, Composer 2, Mago ^1.48.1.

```sh
composer validate --strict
php tests/run.php
php tests/preset.php
php tests/analyzer.php
```

Installer tests cover configuration creation, custom vendor directories, repeated
installation, preservation of all eight user configuration variants, and fallback
source paths.
`tests/preset.php` runs the real Mago executable and checks Laravel casts and
validation rules, visible secret warnings, a nested loop in finally, eval in tests,
and failures for eval in application code and invalid syntax. Install dependencies
first. To use an external executable, set `MAGO_BINARY` to a native executable on
Windows or to the Composer PHP script at `vendor/bin/mago`.

`tests/analyzer.php` runs the real Mago analyzer and extension worker against
isolated framework declarations. It checks magic calls, chained and callback model
types, named arguments, invalid calls, and fallback for custom behavior. Use the
comparison script with a Laravel application to verify the installed framework.

A real Composer path repository installation was also checked in an isolated
Windows project: automatic configuration creation, native `vendor/bin/mago.bat lint`,
Laravel integration, an application rule override, configuration preservation on
repeated `composer install`, and a nonzero exit status for invalid PHP.
That check used Mago 1.48.1; a full Laravel CI run was not performed.
