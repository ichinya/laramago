# Optional runtime database schema metadata

The default analyzer and native Mago lint do not run this collector or require a
database. Runtime schema metadata is a separate, explicit option from the existing
`--evaluate-runtime` configuration/authentication mode.

## Collect and enable

Generate a snapshot for an explicit model selection in the application you want
to analyze:

```sh
vendor/bin/laramago-runtime --project-root . --model 'App\Models\User' --model 'App\Models\Order' --output storage/framework/laramago-schema.json
```

The application must have its Laravel dependencies installed. The collector uses
the application's Composer autoloader, including a configured custom `vendor-dir`;
`--autoload /absolute/path/autoload.php` selects it explicitly.

PHP error display and logging are disabled before application code runs. If needed,
the CLI starts PHP again with the same effective INI settings, including command-line
overrides. Configuration values travel through a temporary file restricted to the
current user and removed on completion. This requires `proc_open`; Windows also uses
its built-in `whoami` and `icacls` tools to restrict access before writing the file.
A writable temporary directory permitted by PHP's filesystem restrictions is
required for this relaunch; failure stops collection before application autoload.
Analyzer revalidation supplies the privacy flags directly and keeps its collector
as one immediate child process under the existing deadline.

When using PHP startup hooks such as `auto_prepend_file` or `auto_append_file`, set
`-d display_errors=0 -d log_errors=0` on the PHP command to avoid relaunching those
hooks. The same flags also avoid the extra process for direct collection.

Enable the snapshot for an analysis by setting `LARAMAGO_RUNTIME_METADATA` to its
absolute path, or by appending `--runtime-metadata=/absolute/path/snapshot.json`
to the PHP worker command in the application's Mago configuration. Every worker
uses the same supplied snapshot. The path is required to be absolute.

```toml
[extension-hosts.laramago]
command = ["php", "-d", "display_errors=stderr", "vendor/ichinya/laramago/bin/laramago-worker.php", "vendor/autoload.php", ".", "--runtime-metadata=/absolute/path/storage/framework/laramago-schema.json"]
```

Regenerate the snapshot after changing its inputs. It is private application
metadata; keep it in an ignored application directory, outside the package's
source and release artifacts. Do not commit it to this package. Enable only
snapshots collected from an application you trust: their stored collection plan
is executed again during revalidation, including an explicitly selected autoloader.

Successful collection exits with status 0. A JSON result containing collection
errors exits with status 1 and cannot be enabled as a usable snapshot. Invalid
arguments, encoding or output failures exit with status 2. The limits are 128
models, 2,048 columns per model, 4,096 source files and 4 MiB of JSON. Revalidation
has a five-second child-process deadline and 4 MiB bounds on stdout and stderr.

## Supported boundary

The initial supported driver is file-backed SQLite. Models resolve their table,
named connection and cast map in a separate PHP process. The collector loads
environment variables, configuration and facades and registers Laravel's database
provider. It does not run the normal application service-provider registration or
boot sequence. Applications that need those providers to resolve a model are
outside this initial boundary and produce an error rather than invented types.
Existing package aliases may be read from the application's package cache; the
collector does not generate a missing package cache or run package discovery.
Generated or hidden SQLite columns and in-memory/URI SQLite databases are outside
the initial supported schema boundary and are rejected explicitly.

Laravel queries are blocked while loading application configuration and preparing
models. Schema collection uses a separate read-only SQLite connection and reads
catalog metadata, not application records. The collector does not invoke model
accessors, caster methods to sample values, migrations, persistence or query
builders to infer types. Different connections remain separate even when their
table names match; the configured table prefix is applied once and the schema is
read from the write database when a read/write connection is configured.

This is trusted application PHP execution, not a sandbox. Composer autoload files,
`bootstrap/app.php`, configuration, model constructors/boot hooks and `casts()`
may execute application code. The Laravel query guard does not constrain direct
PDO calls, files, network access or subprocesses created by that code. Use only
applications you trust. Ordinary application output and collection failures are
rejected; diagnostics do not include exception messages or credentials.

## Types and freshness

Runtime information follows the existing native and PHPDoc contract checks.
Accessors and custom casts retain distinct read and write contracts. Built-in cast
reads include `null` for new or partially populated models, even when the database
column is `NOT NULL`; nullable input follows the schema contract. Custom casts can
transform SQL `null` into a non-null value, so their declared method contracts
remain authoritative.

Uncast SQLite affinity alone does not prove a PHP type. Raw primitive refinement
requires a supported `STRICT` table column. Unknown types, unsupported drivers,
missing tables and unavailable databases are not replaced with `mixed` merely to
remove diagnostics. Models outside the explicit snapshot selection retain the
static analysis workflow.

The snapshot records its collection plan, model/connection/table metadata and
source revisions. It contains neither credentials nor configuration values,
records, column defaults or comments. Custom cast constructor arguments are
omitted; supported built-in cast parameters such as decimal precision remain.
The database path and prefix contribute a hashed connection identity rather than
being exported as a connection configuration. Application inputs and the snapshot itself
are checked before refinements. A bounded child process recollects metadata before
analysis and before publishing the final result. Schema changes are detected even
when no migration source changes. A stale or failed collection emits a runtime
metadata Error and prevents a clean analysis result; collect a new snapshot and
rerun after resolving the failure.

The snapshot is evidence for one analysis, not a persistent proof that a remote
database will remain unchanged. Default analysis and lint remain usable without
the snapshot or a database.

## Verification

`composer test:runtime-schema-boundary` runs the dependency-free snapshot and
process-boundary controls, including malformed responses, output limits and a
genuine child-process timeout. The regular `composer test` includes this gate.

`composer test:runtime-schema` additionally requires an isolated real Laravel
installation and PDO SQLite. Missing dependencies are a failure, not a skipped
successful test. Prepare an ignored test installation, then point
`LARAMAGO_RUNTIME_TEST_AUTOLOAD` at its absolute Composer autoload path:

```sh
mkdir -p var/runtime-test-framework
composer require --working-dir=var/runtime-test-framework --no-interaction --no-plugins --no-scripts 'laravel/framework:^12.0'
LARAMAGO_RUNTIME_TEST_AUTOLOAD="$(pwd)/var/runtime-test-framework/vendor/autoload.php" composer test:runtime-schema
```

The integration suite creates only synthetic models and SQLite schemas. It tests
actual native Mago results, default lint/analysis, nullable and distinct read/write
contracts, one/three workers, named connections, prefixes, schema/source changes,
bootstrap/query failures and redaction. Fixture setup creates SQLite tables;
collection does not run migrations or read application records. `MAGO_BINARY` and
`MAGO_SDK_AUTOLOAD` may select a matching alternative native binary and SDK.
