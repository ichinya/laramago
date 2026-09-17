# ichinya/laramago

A Composer package with a Laravel preset for the native Mago CLI.

```sh
composer config repositories.laramago vcs https://github.com/ichinya/laramago
composer require --dev ichinya/laramago:0.0.7
vendor/bin/mago lint
```

During installation, Composer asks for permission to run the `ichinya/laramago`
plugin. Once allowed, the plugin creates `mago.dist.json` in the application root.
The `carthage-software/mago` dependency provides `vendor/bin/mago`; this package
uses that executable directly, without a wrapper or Laravel service provider.

Version `0.0.7` adds model-aware Eloquent reads, sorting and aggregate signatures.
The GitHub VCS repository shown above provides this version directly. For local
package development, see the path repository instructions below.

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
to Mago's existing method analysis until their semantics are supported. Other magic
methods, scopes, and facades are still outside this extension's coverage.
The package does not generate overlays or
replace Larastan. `examples/compatibility.toml` is an optional fragment with
targeted suppressions for your `[analyzer]` section.

The worker uses Mago's bundled PHP SDK and the application's Composer autoloader.
It does not bootstrap Laravel or connect to a database. The `php` executable must
be on PATH; a project may override the worker command with a specific executable.

### Model properties without a database

`vendor/bin/mago analyze` also resolves Eloquent properties from static source.
There is no Artisan step, migration execution, model instantiation, or database
inspection. The worker still requires the application's Composer autoloader.
The linter command and its preset remain independent of model analysis.

Supported information includes:

- Migration `up()` methods with literal `Schema::create()` and `Schema::table()`
  calls, column types, nullability, timestamps, foreign IDs, morph columns,
  column/table renames, drops, and `change()`. `down()` methods are ignored.
- Explicit or inherited `$table`, conventional English table names, and model keys.
- `$casts` and literal `casts()` arrays, including inherited and trait methods:
  scalar, decimal, array/JSON, collection, object, date, immutable date, and enum casts.
- Typed `getNameAttribute()` / `setNameAttribute()` methods and
  `Attribute<TGet, TSet>` contracts, with separate read and write types.
- Standard relations with PHPDoc type arguments or a direct
  `return $this->belongsTo(Related::class)`-style declaration. Many relations return
  `Collection<int, Related>`; singular relations include `null` unless a statically
  recognized `withDefault()` call provides a default.

Native PHP properties and existing `@property`, `@property-read`, and
`@property-write` contracts retain priority. Casts refine schema types and preserve
schema nullability; without a known column, cast-derived attributes include `null`.
Decimal casts read as `numeric-string`, while numeric writes are accepted. Uncast
decimal and boolean columns retain driver-dependent numeric/string and boolean/flag
alternatives; explicit casts give them precise PHP types. Ordinary date
casts use `CarbonInterface` to allow Laravel's configurable date implementation;
explicit immutable casts use `CarbonImmutable`.

The worker finds the application root through Composer's installed-package metadata,
including custom vendor directories. It recursively reads `database/migrations`
and processes files in filename order. Add other migration directories in the
application's `composer.json`:

```json
{
    "extra": {
        "laramago": {
            "migration-paths": ["Modules/Billing/database/migrations"]
        }
    }
}
```

These paths supplement `database/migrations` and are relative to the application
root; absolute directory paths are also accepted. For isolated analysis workspaces,
the worker accepts an optional third command argument specifying the project root:

```toml
[extension-hosts.laramago]
command = ["php", "vendor/ichinya/laramago/bin/laramago-worker.php", "vendor/autoload.php", "."]
```

Migrations are read even when Mago analyzes only one application file. Metadata is
cached only within a worker's analysis generation; subsequent runs read changed
files. A missing default migration directory is supported. Read/parse failures or
invalid migration configuration emit `ichinya/laramago/metadata-unavailable` warnings
with the affected path and disable unreliable schema inference.

This is a declarative schema reader, not a PHP interpreter. SQL dumps, arbitrary
SQL or helper calls, conditional/dynamic schema changes, custom connections,
custom cast classes, runtime table/cast changes, and untyped accessors are not
inferred. Uncertain metadata stays unresolved; `$fillable` alone does not establish
a type. Unknown properties and invalid assignments remain visible. Properties on
collections, unbound base `Model` values, and request input properties remain
outside this property provider's coverage.

### Factory results

The analyzer also tracks factory result types without creating models
or executing factory definitions:

```php
User::factory()->create();                  // User
User::factory()->active()->make();          // User, for a simple declared state
User::factory(3)->create();                 // Collection<int, User>
User::factory()->count(1)->create();         // Collection<int, User>
User::factory(3)->count(null)->create();     // User
User::factory(3)->createOne();               // User
```

Factory discovery reads `HasFactory<YourFactory>`, `UseFactory`, a static `$factory`
property, or a concrete non-null `newFactory()` return contract. The default
`App` / `App\Models` and `Database\Factories` naming convention is also supported.
Model identity comes from the factory's `$model`, `Factory<YourModel>` declaration,
or the default naming convention. These declarations must describe the actual
factory association; application classes and custom resolvers are never executed.

Count information survives assignments and standard fluent state methods. A
single-return custom state chaining standard factory methods is also understood.
`forEachSequence()` selects a collection; `sequence()` preserves the current count.
Quiet creation, `makeOne()`, `createMany()`, `makeMany()`, `new()` and `times()` are
covered. An integer count always selects a collection, including zero and one.

Unknown counts, unpacked arguments, arbitrary custom states, and merged single
and multiple branches retain `Model|Collection<int, Model>`. Core factory overrides
and subclasses that directly mutate count/model state defer to native analysis.
Runtime naming callbacks and custom collection implementations are not inferred.
Native argument checks, protected property access, missing methods and invalid
collection property access remain active.

### Primary-key lookups

Laramago resolves `find()`, `findOrFail()`, `findOrNew()`,
`findMany()` and `findSole()` on models and the standard Eloquent `Builder`:

```php
User::find(1);                         // User|null
User::findOrFail(1);                   // User, when the call returns
User::findOrNew(1);                    // User
User::find([1, 2]);                    // Collection<int, User>
User::query()->findOrFail([1, 2]);     // Collection<int, User>
User::where('active', true)->find(1);  // User|null
```

Arrays and `Arrayable` identifiers select a collection, including empty arrays.
`findMany()` always returns a collection; `findSole()` returns one model when it
succeeds. Unknown identifiers, scalar/array unions and unpacked argument lists
retain all possible result branches. `find()` keeps `null` in its scalar branch.
No query is executed, and the analyzer does not assume that a database row exists.

Magic model calls use parameter names, types and defaults from the installed
Laravel builder, so named arguments, argument counts and invalid arguments remain
checked. Declared methods and `@method` contracts retain priority, including
inherited declarations. Custom builders, query factories, magic dispatchers and
collection factories defer to native analysis. Relation forwarding and `findOr()`
callbacks remain separate work. First-class callable expressions stay callable;
their later invocation is left to native analysis.

### Creating models

Laramago resolves `create()`, `createQuietly()`, `forceCreate()`,
`forceCreateQuietly()`, `firstOrNew()`, `firstOrCreate()`, `createOrFirst()` and
`updateOrCreate()` on models:

```php
User::create(['name' => 'Ada']);                       // User
User::firstOrNew(['email' => 'ada@example.com']);       // User
User::firstOrCreate(['email' => 'ada@example.com']);    // User
User::updateOrCreate(['id' => 1], ['name' => 'Ada']);    // User
```

Results retain the concrete model class, including inherited and instance calls.
These methods return one model when they return successfully. This type does not
prove that a row was persisted: `firstOrNew()` may return an unsaved model, and
model events may cancel a save. No model constructor, event, callback, application
bootstrap or database query is executed during analysis.

Parameter names, defaults and types come from the installed Laravel builder.
This includes version-specific support for closure values; methods absent from
that builder remain unknown. Native analysis handles declared builder calls such
as `User::query()->create()`. Explicit methods, trait methods and PHPDoc contracts
take priority. Custom builders, query dispatch, instance factories, hydration and
event/guard wrappers defer to native analysis. Custom collections do not prevent
inferring a single model result.

Invalid arguments, unknown properties and methods, and invalid property writes
remain visible. Attribute arrays are checked against the builder signature;
per-column mass-assignment validation and relationship creation remain separate
work. First-class method references remain callable.

### Reading and sorting models

Laramago resolves `first()`, `firstOrFail()`, `sole()`, `get()`, `latest()`,
`oldest()`, `orderBy()`, `orderByDesc()`, `count()`, `sum()`, `exists()` and
`doesntExist()` on models:

```php
User::first();                                  // User|null
User::firstOrFail();                            // User, when the call returns
User::sole();                                   // User, when the call returns
User::get();                                    // Collection<int, User>
User::latest()->first();                        // User|null
User::orderBy('name')->orderByDesc('id')->get();  // Collection<int, User>
User::where('active', true)->orderBy('id');       // Builder<User>
User::count();                                  // installed integer contract
User::exists();                                 // bool
```

Inherited, instance and class-string calls retain the model class. Forwarded
`orderBy()` and `orderByDesc()` also preserve the model type on standard Eloquent
builders. Declared builder reads use Laravel's own generic return contracts.

Parameter names, defaults and types come from the installed Eloquent or Query
Builder, including version-specific sorting directions. Aggregate return types
also use installed metadata: `count()` can retain `int<0, max>`, while `sum()`
stays `mixed` when that is Laravel's contract. Missing methods stay unknown.

Declared methods and PHPDoc retain priority. Custom builders, query factories and
magic dispatchers defer to native analysis. Reads also defer for custom hydration,
instance factories and collection contracts. A named scope that shadows a forwarded
Query Builder method prevents this provider from assigning the standard result.
Local scope inference, runtime macros, relation forwarding and higher-order
collection proxies such as `->map->someMethod()` remain separate work.

No query, model constructor or application bootstrap runs during inference.
`first()` remains nullable, and the analyzer does not assume a row exists.
Unknown properties, collection property access, invalid writes and argument errors
remain visible. First-class method references remain callable.

### Validated form input

Laramago resolves the complete result of
`Illuminate\Foundation\Http\FormRequest::validated()` as `array<array-key, mixed>`:

```php
$request->validated();                   // array<array-key, mixed>
$request->validated(null);               // array<array-key, mixed>
$request->validated(default: []);         // array<array-key, mixed>
$request->validated('title');             // native type, normally mixed
$request->validated('title', 'Untitled'); // a default does not type existing input
```

Omitted keys and keys known to be `null` return the entire validated array.
Named arguments and inherited form requests are supported. Non-null or unknown
keys and unpacked argument lists defer to native analysis. Native parameter
checking remains active; first-class method references remain callable.

Explicit overrides, trait methods, PHPDoc contracts and more precise framework
return types retain priority. Requests that redeclare the validator property
also defer to native analysis.

This provider does not execute the request, its validation rules, callbacks or
application bootstrap. It does not infer field names or types from rules, assert
that a particular key is present, or cast validated strings to numbers. Individual
array values and field lookups retain their native types. Ordinary `input()` calls
and magic request properties remain unchanged.

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

PHP 8.2+, Composer 2, Mago ^1.48.1, PHP-Parser ^5.8, Doctrine Inflector ^2.1.

```sh
composer validate --strict
php tests/run.php
php tests/preset.php
php tests/analyzer.php
php tests/properties.php
php tests/factories.php
php tests/find.php
php tests/create.php
php tests/validation.php
php tests/queries.php
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

`tests/properties.php` exercises the real analyzer with migrations outside the host
file set, without `.env` or application bootstrap. It covers property contracts,
schema changes, relationships, inherited/trait metadata, invalid accesses and writes,
additional migration directories, paths with spaces, concurrent worker requests,
fresh metadata on subsequent runs, and visible parse failures. Fixture PHP remains
in `.stub` files in the package so it cannot shadow the installed Laravel framework.

`tests/factories.php` checks concrete factory discovery, count state through chains
and variables, single and collection results, named arguments, custom declarations,
unknown state and branch unions, protected properties, and native negative cases.
Factory fixtures contain execution traps and require no environment file or database.

`tests/find.php` exercises model and builder lookups, nullable and collection
branches, `Arrayable`, union and unpacked identifiers, first-class callables,
native argument validation, inheritance, PHPDoc priority and custom behavior.
The real worker analyzes isolated declarations without loading an application.

`tests/create.php` checks model creation and native builder calls, inherited and
late-static results, argument errors, fresh property types, custom declarations
and callable references. A second worker run changes the fixture's builder
signature to verify version-specific callback support and unavailable methods.
The isolated project has no environment file or database and contains execution
traps for model construction and application bootstrap.

`tests/validation.php` checks complete validated arrays, null and named keys,
unknown field values, native argument errors, inheritance, overrides and PHPDoc
priority. A fresh worker run verifies a more precise framework return contract.
The isolated project contains execution traps and needs no environment file or
database; only the scenario file is analyzed, with request declarations included
as dependencies.

`tests/queries.php` checks model reads, sorting chains, aggregates, concrete model
and collection types, nullable results, invalid returns and arguments, overrides
and scope collisions. A fresh worker run verifies changed sorting direction and
count contracts, plus unavailable methods. Only the scenario file is analyzed;
framework and model declarations are dependencies. The workspace has a path with
spaces, concurrent workers, execution traps, and no environment file or database.

A real Composer path repository installation was also checked in an isolated
Windows project: automatic configuration creation, native `vendor/bin/mago.bat lint`,
Laravel integration, an application rule override, configuration preservation on
repeated `composer install`, and a nonzero exit status for invalid PHP.
That check used Mago 1.48.1; a full Laravel CI run was not performed.
