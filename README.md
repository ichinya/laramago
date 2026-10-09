# ichinya/laramago

A Composer package with a Laravel preset for the native Mago CLI.

```sh
composer config repositories.laramago vcs https://github.com/ichinya/laramago
composer require --dev ichinya/laramago:0.1.0
vendor/bin/mago lint
```

During installation, Composer asks for permission to run the `ichinya/laramago`
plugin. Once allowed, the plugin creates `mago.dist.json` in the application root.
The `carthage-software/mago` dependency provides `vendor/bin/mago`; this package
uses that executable directly, without a wrapper or Laravel service provider.
For editor integrations, `vendor/bin/laramago-metadata` exports [source-only
configuration, route and translation metadata](docs/static-metadata-export.md) as versioned JSON.

Version `0.1.0` expands source-bound model, collection, container, callback and
configuration type contracts. It adds guarded compatibility checks for array,
string, null-flow, date, header, session, XML and factory/Faker patterns. Unknown
dispatch and code outside the proven contracts retain native diagnostics.
It also refines installed framework argument contracts, string predicates,
backed enum value columns, structural XPath results, native `never` calls and
process descriptor redirects, with LF/CRLF source regression checks.
It includes the Laravel integrations from earlier releases.
For local package development, see the path repository instructions below.

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
- A bounded defensive-check policy permits a fresh local array guard protecting
  a literal-key read followed immediately by a throwing string check. Native
  array types and argument/return errors remain active; other redundant checks
  retain their diagnostics. See the [compatibility policy](docs/phpstan-compatibility.md).
- Direct script assignments of an `include` or `require` result permit untyped
  variable storage. The result stays `mixed`; unsafe uses and stronger variable
  annotations retain native analysis.
- A local object `@var` on a plain assignment or ordinary `foreach` header may
  repeat the inferred type.
  Laramago permits this documentation when native Mago certifies exact equality
  and current source and class metadata confirm the binding. Types and usage
  errors remain active; stronger or uncertain annotations retain diagnostics.
- An object `@var` before the first method guard in a by-value `foreach` may
  repeat its existing type when native Mago certifies exact equality. The
  current loop binding, enclosing scope and concrete class must agree; usage
  errors and stronger annotations retain native analysis.
- The same native equality policy permits a local object tag on an immediate
  argument to `$this` after a matching `instanceof` guard. Intervening statements,
  references, compound conditions and uncertain bindings retain diagnostics.
- Exhaustive string checks on a fresh decoded JSON list can establish an
  optional `list<string>` field in a yielded record. The declared iterable
  contract remains authoritative; unchecked elements and incompatible fields
  retain errors. See the [compatibility policy](docs/phpstan-compatibility.md).

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

Declared methods keep their native behavior. Custom query factories and magic
dispatchers defer when their behavior is not statically known. Explicit custom
builders, local scopes, and additional bounded integrations are described below.
Laravel contracts and [PHPStan-compatible analysis policies](docs/phpstan-compatibility.md)
run directly on Mago. PHPStan and Larastan are used to compare behavior; they are
not required by the extension. No source overlays are generated.
Source-certified native `float` callable boundaries accept numeric strings in
weak PHP mode; strict files and stronger PHPDoc retain their checks. Own physical
methods may coexist with source-certified named `@mixin` declarations; callable
contracts are taken from those physical methods.
Defensive CLI normalization of `$_SERVER['argv']` into a string list permits
the source-proven redundant array check while preserving all native types and
errors. Conditional, referenced or dynamically shared bindings retain their checks.
Ordinary unannotated local assignments and by-value foreach bindings may store
`mixed`, matching PHPStan's advisory policy. Native types and all unsafe-use
errors remain active; references, captures and stronger contracts retain their checks.
`examples/compatibility.toml` remains an optional fragment with targeted
suppressions for your `[analyzer]` section.

The worker uses Mago's bundled PHP SDK and loads the application's Composer
autoloader. The default analyzer does not itself bootstrap Laravel or query a
database. Loading the autoloader can execute Composer `autoload.files` entries;
source inspection does not execute the inspected files. Runtime evaluation is
separately opt-in, as described below. The `php` executable must be on PATH; a
project may override the worker command with a specific executable.

### Local scopes without a database

Local `scopeName()` methods are available through models and the standard
`Builder`, including inherited and trait declarations. The analyzer removes the
implicit query parameter and checks the remaining native/PHPDoc parameter types,
named arguments, defaults, references and variadic parameters.

```php
// public function scopeActive(Builder $query, bool $enabled = true): void
User::active(enabled: true)->findOrFail(1); // User
User::query()->active();                   // Builder<User>
```

Scopes returning `void` or `null` retain `Builder<YourModel>`. Other declared
results are preserved; `int|null`, for example, becomes `int|Builder<YourModel>`.
An untyped scope retains its builder only when its single return expression
provably preserves the query: the query itself, or a native `where`/`orWhere`
chain with safe arguments. Native Query Builder forwarding is also recognized
for the supported column, membership, null, range, and date predicates, as well
as `orderBy` and `orderByDesc`, provided
the installed method returns itself and no model scope, declaration, or PHPDoc
contract shadows the forwarded operation. Safe arguments include recursively
checked arrays without references or unpacking, literals, scope parameters, and known
enum case `name` or backed enum case `value` properties. Other untyped scope
results stay unknown, except for a bounded enum conversion: an
`if ($value instanceof SomeEnum)` containing one query-preserving return, followed by an
unconditional query-preserving return. Within that branch, the enum parameter's
`name` (or backed enum `value`) is safe; the guard does not apply to the fallback.
Additional statements, side effects, and other branch layouts remain unknown.
Generic scope methods and custom query or
scope dispatchers defer to native analysis. Explicit methods and PHPDoc contracts
retain priority. No scope body, model constructor or application bootstrap runs.

Methods marked with `#[Scope]` are supported through `User::query()->active()`.
Direct calls such as `User::active()` to a protected attributed method still
produce Mago's native visibility/static-call diagnostics: the SDK signature
provider cannot change access to a declared method. Use an explicit builder call
for these scopes; Laramago does not suppress access diagnostics.

`php tests/scopes.php` verifies scope behavior through the real Mago worker in
an isolated workspace with spaces in its path, without an environment file or
database. Fixtures include execution traps and negative argument/access cases.

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
- Native `Model::getKey()` reads a proven primary-key attribute without becoming
  `mixed`; the result conservatively retains `int|string|null` for unsaved
  models and driver differences. Custom key readers and unknown attributes defer.
- `$casts` and literal `casts()` arrays, including inherited and trait methods:
  scalar, decimal, array/JSON, collection, object, date, immutable date, and enum casts.
- Custom `CastsAttributes` classes with concrete native/PHPDoc contracts on
  `get()` and the value parameter of `set()`, including inherited/trait methods
  and array shapes. Read and write types are independent.
- `CastsInboundAttributes`: the write contract comes from `set()` and the read
  contract from a known migration column. Without that column, analysis defers.
- Typed `getNameAttribute()` / `setNameAttribute()` methods and
  `Attribute<TGet, TSet>` contracts, with separate read and write types.
- Standard relations with PHPDoc type arguments or a direct
  `return $this->belongsTo(Related::class)`-style declaration. Many relations return
  `Collection<int, Related>`; singular relations include `null` unless a statically
  recognized `withDefault()` call provides a default.

Native PHP properties, typed accessors and explicit `@property-read` contracts
retain priority. A known cast determines the read type ahead of a general
`@property` tag, including supported PHPStan and Psalm tags. Compatible array
casts preserve declared list items, keys and shapes. Explicit `@property-write`
tags retain priority. A virtual general `float` tag with a known decimal cast
uses the cast's `int|float|string` input contract; other general write contracts
retain their types. Casts
preserve schema and documented nullability; without a known column,
cast-derived attributes include `null`. Unresolved casts retain native analysis.
Decimal casts read as `numeric-string`, while numeric writes are accepted. Uncast
decimal and boolean columns retain driver-dependent numeric/string and boolean/flag
alternatives; explicit casts give them precise PHP types. Ordinary date
casts use `CarbonInterface` to allow Laravel's configurable date implementation;
explicit immutable casts use `CarbonImmutable`.

Custom casts run on null values in Laravel, so their method contracts determine
nullability independently of the database column. A nullable column can produce
a non-null object; a non-null column can still have a nullable cast result.
Laramago never constructs a caster or invokes `get()`, `set()` or `castUsing()`.
For `Castable`, a factory consisting solely of `return ConcreteCaster::class`
or `return new ConcreteCaster` is resolved statically, including inherited factories.
Conditional factories, constructor arguments, contextual class names, anonymous
casters and recursive Castable targets remain unresolved.
Literal caster strings with constructor arguments are supported when the static
model metadata resolves the string. Constructor arguments are not executed.
Generic interface bindings alone are not used to narrow a `mixed` method
parameter; use an explicit method PHPDoc contract for that case.

Inherited template contracts can be resolved through verified direct
`@extends`/`@implements` bindings. Scalar, named-class and nullable union arguments,
nested named generics, lists, array maps and explicit array shapes are supported
across multiple inheritance levels. Named generic existence and arity are checked;
template constraints remain subject to native Mago declaration validation.
Explicit concrete or `mixed` method contracts retain priority. Unsupported type
expressions, trait/method templates, ambiguous inheritance paths and unresolved
mappings defer to native analysis.
The same resolver can handle one literal `Castable` factory hop.

`php tests/casts.php` checks custom casts through the real worker, including
negative assignments, nullable behavior, inheritance, traits and execution traps.
`php tests/generic-casts.php` adds template substitution and unresolved-binding
regressions through the real analyzer.
`php tests/cast-contracts.php` checks nested ancestry arguments and malformed,
overflowing or unresolved type-expression boundaries.

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

Within `Schema::create()` and `Schema::table()` callbacks, a fresh local variable
may prepare a scalar expression without making the whole table unknown:

```php
Schema::create('entries', function (Blueprint $table) {
    $table->id();
    $table->string('code');
    $table->string('label');
    $expression = DB::getDriverName() === 'sqlite'
        ? 'code || label'
        : 'concat(code, label)';
    $table->string('display')->virtualAs($expression);
    $table->unsignedInteger('quantity')->nullable();
});
```

Literal scalars, scalar operators, ternaries, string interpolation and earlier
scalar locals are recognized. The only recognized call in these expressions is
the resolved Laravel `DB::getDriverName()` facade call without arguments. Laramago
does not invoke it, select a database driver, or evaluate the SQL expression.
Column types and nullability still come from their explicit Blueprint declarations;
`virtualAs()` and `storedAs()` do not supply a type themselves.

Assignments must introduce a previously unmentioned local, excluding callback
parameters, captures and superglobals. Reassignments, references, objects,
arbitrary calls and conditional schema changes leave the table uncertain. Proven
literal scalars and flat lists, including fresh copies, can supply column names,
nullable modifiers, rename targets and drop lists. Computed expressions are never
folded into values. Literal knowledge expires when a non-preparation statement
mentions a local, protecting against references passed through column arguments.
Reusing an expired local, using captured values or mutating arguments leaves the
affected schema uncertain. These rules apply only inside Blueprint callbacks;
arbitrary migration setup remains unsupported. `php tests/migration-literals.php`
covers the literal substitutions and their negative cases.

Simple `foreach` declarations can use a flat literal list or a fresh list local:

```php
foreach (['title', 'summary'] as $column) {
    $table->string($column)->nullable();
}
```

The target must be a fresh by-value variable and the body must contain direct
Blueprint calls. Key targets, references, nested loops, branching, helper calls
and mutations defer. Expansion is bounded to 64 elements, 64 body statements and
256 expanded statements. Loop bindings do not establish types for later uses.
`php tests/migration-contracts.php` covers these boundaries without executing PHP.

Literal-string `DB::raw(...)` wrappers in Blueprint arguments remain supported,
including a fresh proven string local. Their SQL is never interpreted or executed.
Dynamic raw arguments and arbitrary nested calls leave the schema uncertain.

This is a declarative schema reader, not a PHP interpreter. SQL dumps, arbitrary
SQL or helper calls, conditional/dynamic schema changes, custom connections,
dynamic `Castable::castUsing()` factories, unresolved generic cast contracts,
runtime table/cast changes, and untyped accessors are not
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
Runtime naming callbacks remain unresolved. Counted `create()`, `createQuietly()`
and `make()` preserve a statically resolved model collection class. Laravel's
`createMany()`, `createManyQuietly()` and `makeMany()` construct the base Eloquent
collection, so those retain the base collection type.
Native argument checks, protected property access, missing methods and invalid
collection property access remain active.

### Custom Eloquent builders

Native query entry points (`query()`, `newQuery()`, `newModelQuery()`,
`newQueryWithoutScopes()` and `newQueryWithoutRelationships()`) preserve a known,
non-generic Eloquent builder subclass declared through `$builder`,
`#[UseEloquentBuilder]` or a concrete `newEloquentBuilder()` return contract.
Inherited declarations and positional/named attribute arguments are supported.

```php
// User declares a UserBuilder with an active() method.
User::query()->active(); // UserBuilder; native argument checks stay active
```

The custom builder's own signatures and `@extends Builder<User>` declaration
control subsequent calls. Explicit factory PHPDoc such as
`@return CustomBuilder<User>` preserves concrete generic arguments; class-string
selectors without arguments remain unresolved for generic builders.
Public custom methods also support direct calls such as `User::active()`, with
native argument checks and builder-aware fluent results. Explicit class template
arguments are substituted through parameters, results, lists and array shapes;
the defining class and template constraints are verified. Fluent `static`/`$this`
returns keep the bound builder, and explicit factory `CustomBuilder<static>`
contracts follow inherited model receivers. Verified parent-class `@extends` arguments also propagate through reordered and multi-level ancestry, with each template constraint checked. Method templates, generic traits, unresolved callable containers and reference signatures defer.
`php tests/builder-generics.php` and `php tests/builder-ancestry.php` check these
specializations and their negative cases.
Existing query overrides remain native; dynamic builder resolvers and
custom construction chains defer to native analysis. Constructors and factory
bodies are never executed. Tests run with `php tests/builders.php`.

### Custom Eloquent collections

Model reads and primary-key lookups preserve an explicitly known, non-generic
Eloquent collection subclass declared through:

- A concrete `newCollection()` return type, including inherited declarations.
- A literal `$collectionClass` class name.
- A positional `#[CollectedBy(CollectionClass::class)]` attribute, including
  inherited attributes. The nearest class attribute takes priority.

For example, `User::get()`, `User::query()->get()` and `User::findMany([1])`
retain a declared `UserCollection`, making its custom methods available for
analysis. A custom `newCollection()` return contract takes priority over class
attributes and properties. No collection constructor or factory is executed.

Explicit `newCollection()` PHPDoc such as `@return CustomCollection<int, User>`
preserves concrete generic arguments. Generic class-string selectors without
arguments, unresolved return types and custom
`resolveCollectionFromAttribute()` implementations still defer to native analysis.
Collection subclasses can declare a fixed element type with `@extends`; Laramago
does not invent template arguments from their names or parameter count. This
support also covers counted factory results as described above.

Calling the verified native `newCollection()` with no arguments or an empty
array also preserves the receiver model's collection contract and integer keys.
Populated arguments and custom factory implementations retain native analysis.

### Additional static integrations

These integrations inspect declarations and syntax without booting the application.
Unknown dynamic behavior keeps native Mago diagnostics and types.
The [coverage matrix](docs/static-analysis-support.md) lists the implemented and
deferred portions of these integrations.

| Area | Supported subset | Boundaries |
| --- | --- | --- |
| Relationship methods | Standard factories, trait declarations and explicit native pivot modifiers retain related/declaring/intermediate model types | Explicit PHPDoc wins; unresolved model/trait generics and custom factories defer; [MorphTo properties](docs/morph-properties.md) retain nullable Model bounds or explicit model unions |
| Relation results | Standard relation forwarding for results, sorting, aggregates and 24 key/membership/null/range/date predicates | Native declarations win; custom relations, `MorphTo` and custom related builders defer |
| Selected query fields | Direct model `value`/`pluck`, including literal keys and exact-table qualification; terminal queries preserve literal aliases on their own result | Stateful builder projections, expressions, `withCount`/`withSum` and unknown schema defer; `sum` keeps its installed contract |
| Literal query columns | Fresh literal `Model::query()` terminal chains check filters and ordering against an [explicit effective-source and native-semantics contract](docs/query-source-contracts.md) | Joins, source/projection/raw mutations, scopes, saved builders, dynamic columns, custom dispatch, direct static forwarding and nonterminal chains defer |
| Related projection columns | Closed native eager-load colon projections checked against explicit [owner-relation source contracts](docs/related-projection-source-contracts.md) | Pivot/through relations, modifiers, dynamic projections, mutable builders and unasserted relation sources defer |
| Search attribute arrays | Three direct fresh-query [search/create methods](docs/search-attribute-arrays.md) check literal nonnumeric first-argument keys against the query-source contract while leaving creation/update values untouched | Saved or modified builders, direct static magic, scopes, custom dispatch, dynamic/numeric-key arrays, write acceptance, `createOrFirst()` and `findOrNew()` |
| Relationship callbacks | Literal dotted paths for the four `whereHas`/`whereDoesntHave` variants receive `Builder<Related>`; `withWhereHas` receives a Builder/Relation union | Uses authoritative PHPDoc or supported relation bodies; dynamic paths and custom dispatch defer |
| Relation validation | Warns when a referenced existing method explicitly returns a known non-relation class, including nested paths | Missing names are checked only with explicit [complete model contracts](docs/relation-names.md) |
| Model field-list validation | Warns for missing literal names in directly declared `$fillable`, `$guarded`, `$hidden`, `$visible` and `$appends` arrays and their native Laravel class attributes, distinguishing filterable relationship keys from appendable legacy/`Attribute` accessor and class-cast keys | Requires explicit complete exact-model [field, serialization-key and appendable-key catalogs](docs/model-field-catalogs.md); schema-only, inherited, older/custom attribute, runtime-discovered and custom-dispatch definitions defer |
| Authentication | Nullable default models from literal config, explicit Composer auth contracts, or — under the opt-in [runtime evaluation](#runtime-evaluation) below — the local application's resolved auth configuration; standard guards and explicitly declared custom guard classes | Without the opt-in flag environment values are never evaluated and standard selected guard users retain native `Authenticatable|null`; custom guards retain their own user contracts |
| Collection operations | Standard collection null filtering, higher-order map/filter/reject, known model/shape/union items and literal-property aggregates | Custom subclasses, unsafe branches, callback/key filtering and union method calls with arguments defer; see the mapping contract below |
| HTTP test assertions | Typed Laravel and optional Laratesto callbacks, nested fluent scopes, standard Inertia page envelopes and flash assertions | Known installed declarations required; custom contracts/macros win; selected prop and JSON values remain unknown |
| Facades and container helpers | Concrete roots and public service signatures from class-string accessors, installed framework core service aliases or explicit static binding catalogs, including [literal typed factories](docs/container-bindings.md#literal-factory-closures) | Uncataloged aliases, runtime binding discovery, generic/reference contracts and custom dispatch defer; declared methods and PHPDoc win |
| Console command closures | Native `Artisan::command()` callbacks receive `ClosureCommand` as `$this` when installed Laravel constructs and binds that command | Custom Kernel bindings, changed facade or callback binding, static closures and unrelated command methods retain native analysis |
| Console command inputs | Literal entries in an unchanged native command `$signature` refine `$this->option('name')` (`--flag` to `bool`, `--name=` to `string|null`); finite option-name unions combine every member's declared type. `$this->argument('name')` supports single literal names (required to `string`, optional to `string|null`, defaulted to `string`, array to `list<string>`) | Any unknown, reserved or unsupported union member retains native analysis, as do custom constructors, command or input/definition mutation, traits, inherited command bases, dynamic signatures and unsupported forms; these types describe normal CLI input, while programmatic input may supply other values |
| JSON decoding | `json_decode(..., true)` with a literal `true` assoc argument yields `list<mixed>&#124;array<string,mixed>&#124;bool&#124;int&#124;float&#124;string&#124;null`, matching the PHPStan/Larastan value space | Absent, false, null, dynamic or truthy-int assoc arguments retain native `mixed`; depth and flags are ignored because `json_decode` never returns `false` and `null` stays reachable |
| Container contract methods | Method calls on receivers typed exactly as an `Illuminate\Contracts\*` interface bound by the installed framework's core alias table resolve to the root concrete class: a false `non-existent-method` is not reported and declared concrete return types apply | Methods missing on both the contract and the concrete keep their diagnostics; unmapped or foreign interfaces, union/generic receivers and methods the contract declares retain native analysis |
| Class aliases | Global-namespace calls through Laravel's boot aliases (e.g. `\Str::random()`) no longer report a false `non-existent-method` when the literal boot chain — `Facade::defaultAliases()` merged by the framework base config, without project overrides or colliding package aliases — maps the name to a class declaring the method | A project `config/app.php` with any `aliases` key, `dontMergeFrameworkConfiguration()`, unresolvable chain shapes and alias names claimed by installed packages disable the map; a declared global class of the same name and methods the target lacks keep their diagnostics; the call expression itself remains `mixed` |
| Configuration | Literal helper and native Config reads from static configuration arrays, including shapes, known defaults, `getMany`, typed getters and exact native `#[Config]` injection attributes; verified native literal `env()` calls with an explicit scalar default use its generalized declared type; calls without a default and environment-derived configuration values retain the broader native possibilities; missing literal keys under an [explicit complete runtime contract](docs/configuration-keys.md) | Arbitrary repository instances, dynamic env calls/defaults, machine-specific values without runtime opt-in, runtime mutations, package-merged defaults and unasserted missing-key warnings defer |
| Storage disks | Literal native `Storage::disk` and `#[Storage]` names under an [explicit complete runtime contract](docs/storage-disks.md) | Dynamic names, custom facades/managers/attributes, adapters and unasserted runtime mutations defer |
| Translation strings | Known PHP/JSON strings with explicit locales or literal `app.locale`, respecting JSON precedence | Missing literal views/translations can be checked with [complete catalogs](docs/reference-catalogs.md); dynamic locales/loaders defer; native `view()` typing is retained |
| Route parameters | Duplicate placeholders in literal native Router, lexically resolved native Route facade and Route `setUri()` calls; missing required URI keys for closed named URL-parameter arrays under an explicit effective-default contract | [Complete named-route contracts](docs/named-route-contracts.md) check native URL/redirect generators; positional/domain parameters, runtime aliases, middleware and dynamic routing defer |
| Macros | Typed closures, static callable arrays and invokable objects in explicit files or [active provider boot sources](docs/macro-service-providers.md), including known boolean/ordered hasMacro guards | Catalog activation/order/completeness are user guarantees; unknown conditions, dynamic, generic and reference contracts defer; see [macro catalogs](docs/macros.md) |

Static configuration types describe the source defaults; applications that replace
configuration, authentication resolvers or facade bindings at runtime need explicit
contracts. `app(Foo::class)`, `resolve(Foo::class)` and standard container `make()`
already receive native Mago generic inference and require no extra provider.

Opt-in [container binding catalogs](docs/container-bindings.md) additionally resolve
known interface implementations and literal aliases for helpers, native `make`
and facade accessors. Listed registrations are an explicit application contract;
Laramago does not execute or activate them.

A catalog that replaces or makes a core service uncertain also disables related
standard-service inference: `auth` for authentication, `validator` for validated
fields, and `config` for static configuration reads and the default locale.

Standard Inertia `inertiaPage()` results expose the page envelope, including
`component`, `props`, `url`, `version` and `flash`. `inertiaProps()` without a
selector exposes an array of unknown values; selecting a prop does not establish
its type. Flash assertions retain the response type. The optional Laratesto
wrapper refines only a zero-argument `inertiaPage()` call; its forwarded optional
argument is not interpreted as a field selector.
Envelope refinement additionally checks the installed page source and property
contracts. Incompatible implementations retain native types.

Verified Laratesto `PhpUnitCompatibility::assertIsArray`, `assertIsString`,
`assertIsInt`, `assertIsBool`, `assertIsObject` and `assertNotFalse` narrow a
simple local variable after the assertion returns. Laramago checks the installed
Laratesto and Testo implementations before applying this rule. Property chains
and other expressions retain native analysis because their values can change.
Consecutive verified array assertions additionally support literal nested offsets
when each prefix is proven to be a PHP array. Assignments, opaque calls, dynamic
keys and ArrayAccess receivers break this proof.

A native throwing guard for an optional string key can also preserve a nested
array fact in the immediately following matching ternary branch. This requires
fresh associative JSON, a native root-array check, and checks for the literal
field's presence, array type and key. Laramago verifies the source, native helper
metadata and current file bytes before correcting only the proven access.
Named function locals must have no references or hidden access to local variables;
intervening assignments must have fresh or known nonobject values. At top level,
the final root-array check must follow all assignments: replacing an older global
object could invoke a destructor that changes the root. Unsupported control flow,
opaque calls, dynamic consumers and incompatible annotations retain diagnostics.

A fresh local array rebuilt immediately after a throwing discriminator guard
can preserve the integer field required by one of two literal variants. The
guard must exhaustively validate both tags and check the dependent field with
native `is_int()`. Laramago derives the rebuilt shape from the current source
and checks it against the declaring class's constructor contract, including
declared type aliases. A copied primitive field remains independent of later
changes to its original input. The local must remain unexposed until its single
by-value constructor argument. References, captures, mutations, implicit local
access, undefined guard locals, unknown native calls and incompatible annotations
retain diagnostics. Source and metadata identities must agree. The SDK cannot
restore this discriminator correlation directly; the extension corrects only
the exact constructor diagnostic for the independently proven argument.
`php tests/reconstructed-array-shapes.php --integrated` verifies these boundaries.

Native `class_uses()` returns an array with string keys and `trait-string` values
for an object, or an unconditionally declared class name with autoloading enabled.
Unknown names, conditional or incomplete declarations and class names with
uncertain autoloading retain the possible `false` result.

A documented `array<string, string>` return can also be verified from a direct
`return self::MAP` and its literal class constant initializer. Explicit keys must
resolve to nonnumeric strings, and values must be string literals. Dynamic maps,
spreads, duplicate keys, incompatible declarations and late binding defer.

A direct array return can retain its element contract after every element passes
a native `is_string()`, `is_int()`, `is_float()` or `is_bool()` check in `foreach`.
The supported loop throws on rejection and immediately returns the unchanged
by-value parameter. Array/list guards preserve key constraints; empty arrays do
not establish a non-empty return. References, callbacks, mutations, early exits
and user-defined predicate functions retain native diagnostics.

A local result initialized to `null` can also be proven nonnullable after an
exhaustive `foreach` over a literal list. The selector must occur with the same
scalar type and value, and its branch must assign a fresh producer result.
Supported producers have a verified native nonnullable object return or use the
standard uncounted model factory contract. References, early exits, mutable locals,
nullable producers and changed factory implementations retain diagnostics.
Factory proofs verify installed native methods and reject custom result-mapping
changes. Available analyzed-file snapshots must match the source read from disk.
The SDK does not supply snapshots for `source.includes`; those dependencies are
verified against their installed files.

Fresh native `SimpleXMLElement` roots and guarded, literal element-only XPath
results can establish the parent of a child read. A missing first-level child is
an empty element proxy; a further read through an unproven empty proxy remains
nullable. A throwing `count($element->child) !== 1` guard establishes an actual
child for subsequent reads. Unguarded parse failures, opaque XML parameters,
custom classes, writes, unsupported aliases, references and escaped nodes
retain native analysis. Proven XML construction uses omitted options, `0` or
the native `LIBXML_NONET` constant. Other options and constructor URL mode defer.

Mago's PHP SDK cannot restore child types after its native count reconciliation
has replaced them with `mixed`. Laramago therefore also corrects exact native
cardinality and cached child-access diagnostics when current source, builtin
dispatch and the same node provenance independently prove the operation. This
does not suppress application argument or return errors. Cardinality reporting
corrections defer for known or constrained XML payloads and unaudited calls.
Unsupported cardinality requirements and stale or ambiguous source spans also
defer. The native [XPath contract](https://www.php.net/manual/en/simplexmlelement.xpath.php)
retains `null` and `false` on failure; XPath iteration requires an array guard.
`php tests/simplexml-provenance.php --integrated` verifies these boundaries.

Native Eloquent `fresh()` and `refresh()` preserve the exact callable-owned model
template when called without arguments directly in the first statement of a named
function or method. The receiver must be its original by-value parameter with a
template bounded by `Model`. `fresh()` remains nullable. Aliases, captures, later
calls, different bounds and changed native contracts defer.

Native Eloquent `refresh()` may also invalidate a previously observed literal
value of a virtual attribute. Laramago checks the effective refresh, raw-attribute
replacement and read implementations against their installed source, then
checks supported native `Testo\Assert::same()`, `true()` and `false()` calls
against an independently established read contract.
Explicit `@property-read` PHPDoc retains priority; known casts refine general
`@property` reads while keeping explicit directional write types. This
corrects exact stale comparison diagnostics without claiming that persistence or
the assertion succeeds. Known unsaved receivers, no-op overrides, constant
accessors, real PHP property shadows, custom casts, incompatible read types and
stale or incomplete source retain diagnostics. Other flow errors, including
`never` cascades, require their own proof. The SDK cannot forget a cached property
literal directly, so this correction is limited to the proven comparison.
`php tests/refreshed-model-properties.php --integrated` verifies these boundaries.

[Model attribute source freshness](docs/model-attribute-source-freshness.md) also checks that an already certified unscanned dependency remains unchanged after its AST is evicted; changed source defers until exact restoration. Run `php tests/model-attribute-source-freshness.php` for the genuine source mutation and restoration regression.

A successful native `Testo\Assert::notNull()` can preserve a nullable string
getter's result at its immediate repeated call. The receiver must be a final
source-verified class, and the public getter must return one ordinary physical
field without callbacks or side effects. Laramago also verifies the installed
assertion's successful logging path. Nullable declarations stay unchanged;
intervening operations, changed dependencies, magic storage and stronger PHPDoc
defer. Missing analyzed declarations and ambiguous call spans defer as well.
`php tests/asserted-pure-getter.php --integrated` verifies these boundaries.

A direct native `array_map()` over a fresh list of nonempty string literals can
preserve those finite input values at the first constructor argument, even when
the inline callback declares a general `string` parameter. Laramago verifies
the actual callback and constructor metadata against their current source and
checks every literal against the declared `non-empty-string` parameter. Included
dependency declarations are read independently. Empty, computed or unknown inputs,
escaped callbacks, references and stronger conflicting annotations retain native
diagnostics. This correction leaves the general callback declaration unchanged.
`php tests/finite-map-arguments.php --integrated` verifies these boundaries.

Native Eloquent chunk callbacks with a bare `Collection` parameter can lose the
queried model's generic type inside their body. For an unchanged `foreach` item,
Laramago can restore an independently documented public property read when the
query, hydration, collection and iterator contracts are verified from source.
Explicit generic annotations, real base-model properties and unsupported query
effects retain priority. This correction does not infer unknown fields or model
methods. Ambiguous property spans, aliases, references, writes and custom query
or collection implementations defer.
`php tests/contextual-collection-members.php --integrated` verifies these boundaries.
Read-only helpers may read an item property in a dictionary key. Actual field
writes, indexed writes, destructuring and reference escapes still defer.

When an ambiguous base-model property warning survives the native provider,
Laramago can use the exact analyzed file and a verified concrete model declaration
to resolve that advisory. The shared source index keeps property-provider span
collisions conservative. Unknown fields and unsafe uses retain their diagnostics.
`php tests/contextual-documented-property.php` checks the full registry and native controls.

An exhaustive by-value validation loop can establish scalar row fields before
an inline native `usort()` comparator and a direct list return. Laramago verifies
the current guards, callback, caller and documented return contract. References,
missing checks, altered rows and incompatible returns retain errors. A bounded
top-level `getopt()` array guard follows the same lexical compatibility policy.
Native expression types stay unchanged.
`php tests/validated-array-contracts.php` checks these boundaries.

Two [guarded string cast policies](docs/guarded-string-casts.md) preserve native
types while matching PHPStan's declared callable-method guard and Larastan's
benevolent literal Request route conversion. Exact source spans, native caller
and builtin contracts, and unchanged variables are required. Concrete bad objects,
unprotected casts, references, custom implementations and unsupported defaults
retain their diagnostics. `php tests/guarded-string-casts.php` verifies the exact
native delta and one/three-worker agreement.

A directly invoked closure may change a fresh boolean local captured by reference.
Laramago verifies the analyzed caller, final callee and private forwarding helpers,
including native `Closure::fromCallable()` normalization and caught exceptions.
Bounded source constructors preserve physical fields, promotions and the actual
signatures of stored closures and method callbacks. Invalid arguments, inaccessible
fields and unsupported initialization retain diagnostics.
It reconstructs the values reaching a later native `Testo\Assert::true()` or
`false()` comparison; a transient write followed by a reset does not establish
the earlier value. Unknown effects, escaped callbacks or references, stronger
conflicting annotations, incomplete snapshots and stale source retain diagnostics.
This correction is limited to the proven comparison. Deferred listeners and
downstream unreachable-flow diagnostics require separate evidence.
`php tests/direct-callback-reference-effects.php --integrated` verifies these boundaries.

The installed `Internal\Container\Container::get()` and `make()` contracts accept
unions of class strings while retaining their corresponding object union. Bounded
and generic class strings preserve their return contract. Unknown class strings
do not establish a particular service type, and invalid selectors or constructor
argument arrays retain diagnostics. Changed interface contracts defer.

An immediate `is_int($value)` guard after a fresh local `filter_var()` assignment
preserves literal `FILTER_VALIDATE_INT` minimum and maximum bounds. Only the
successful integer branch gains the range. Defaults, dynamic options, references,
intervening statements and unsupported flags retain native analysis.

Inside a direct positive `instanceof` branch, HTTP and console kernel unions use
the selected native interface's argument and return contracts. This avoids
combining a console status code with an HTTP response. Calls outside such branches
and changed framework contracts retain native analysis.

Omitted, null or falsy translation locales use only a literal `config/app.php`
locale and one conventional language root. This models the configured initial
locale; runtime locale changes and custom translation-loader paths require
additional application contracts.

Literal `trans_choice()` references can also receive missing-translation warnings
under [complete effective-locale catalogs](docs/native-translation-choice-references.md).
The helper and `app()` forwarding must match the installed native implementation;
custom helpers, uncertain loaders and unasserted locales retain native behavior.

Tests for these integrations run through `composer check`, including invalid
arguments/results, nullable access and execution traps.

### Relationship method types

A parameterless model method with a native relationship return type can supply
its missing generic arguments from a single direct factory call:

```php
class Author extends Model
{
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}

$author->articles();                // HasMany<Article, Author>
$author->articles()->first();       // Article|null
$author->articles()->get();         // Collection<int, Article>
```

Laramago reads the method body without executing it. Class aliases, inherited
methods, `self::class`, `static::class`, named arguments and literal key names are
supported. The declaring model argument follows the receiver; an inherited
`self::class` target continues to refer to the method's declaring class.

Factories covered are `hasMany`, `hasOne`, `belongsTo`, `belongsToMany`,
`hasManyThrough`, `hasOneThrough`, `morphOne`, `morphMany`, `morphToMany` and
`morphedByMany`. Through relations preserve the intermediate model. Supported
many-to-many template layouts retain their installed Pivot/MorphPivot defaults.
Native `withTimestamps()` and `withTrashedParents()` chains are recognized after
checking their installed declarations; timestamp names must be literal strings
or null.
Native `withDefault()` chains preserve relationship generics for boolean values
and static attribute arrays, including methods without a return declaration.
The installed `SupportsDefaultModels` declaration and its default argument must
match the supported contract; callbacks and dynamic values defer.

Explicit return PHPDoc keeps priority, including an intentionally broad contract.
The installed relationship templates and factory declarations must match the
supported Laravel contracts. Custom factories, relation constructors and related
model resolvers defer. Native visibility and argument checks remain active, as do
unknown-property, invalid-assignment and nullable-result diagnostics.

Relationship properties also defer for private methods and custom
`isRelation`, `getRelationValue` or `getRelationshipFromMethod` dispatch.
Protected relation methods remain accessible to the base model. Empty
`withDefault([])` attributes preserve nullability; the last default modifier wins.
Callback defaults and potentially callable arrays defer because they can replace
the related model with an arbitrary value.
To-many relationship properties use the related model's collection contract,
including explicit `newCollection()` return types, `CollectedBy` attributes and
static collection-class declarations. Unknown collection factories defer;
explicit property PHPDoc retains priority.
Source-derived targets also defer when a model overrides relationship factories,
constructors or related-instance resolvers; explicit relation PHPDoc is preserved.
Explicit unions such as `HasOne<FirstModel|SecondModel>` preserve every model
branch in the property type. To-many unions resolve each model's collection
contract. Invalid documented related types defer instead of being replaced by
a model found in the method body.

Application-trait declarations are supported, including nested imports, aliases
and inheritance. A trait's `self::class` refers to its importing class, while
`static::class` follows the actual receiver. Native `using(Pivot::class)` and
`as('accessor')` modifiers preserve explicit pivot/accessor arguments for verified
four-template relation layouts. Morph relations require a MorphPivot subclass.
The same lexical trait resolution applies to relationship properties, including
inherited and reimported traits, while explicit return PHPDoc remains authoritative.

Methods without a return declaration use the same bounded factory inference,
including their relationship properties. Explicit native and PHPDoc return
contracts retain priority, including `mixed`.

Unresolved model/trait generics, dynamic targets or keys, branches, locals,
arbitrary chained modifiers, nullable method declarations and dynamic
`MorphTo` targets retain native types. Passing a custom pivot as the factory table
argument is outside this inference. `php tests/relation-contracts.php` covers
trait dispatch, pivot contracts and declaration-priority regressions.

Relationship callbacks reuse these inferred types when no authoritative return
PHPDoc is present. Literal dotted paths can combine documented and inferred
relations. `whereHas`, `orWhereHas`, `whereDoesntHave` and `orWhereDoesntHave`
receive `Builder<Related>`. `withWhereHas` also uses the callback for eager loading,
so its argument is `Builder<Related>|ConcreteRelation<...>`. Both cases must be
handled, for example through an explicit union and `instanceof` narrowing.
Arbitrary `with` callbacks, dynamic paths, parameterized relation methods and
custom related builders remain outside this inference.

### Explicit authentication contracts

When authentication configuration depends on environment values, an application
can declare its analysis contract in `composer.json`:

```json
{
    "extra": {
        "laramago": {
            "auth": {
                "default-guard": "web",
                "guards": {
                    "web": {"model": "App\\Models\\User"},
                    "api": {"class": "App\\Auth\\ApiGuard", "model": "App\\Models\\User"}
                }
            }
        }
    }
}
```

These entries are explicit assertions about the analyzed application's runtime
configuration. Laramago reads JSON only; it does not evaluate `env()` or boot a
service provider. Model classes must implement `Authenticatable`; custom guard
classes must implement `Guard`. A model declaration that contradicts a custom
guard's declared `user()` return type is not used. Native and PHPDoc contracts
retain priority, and user results retain nullability.

Custom guard chains use that class's own `user()` contract. Standard framework
guards are not generic, so selecting one does not carry a model argument into
later `user()` calls. Missing or invalid metadata remains conservative.
`php tests/auth-contracts.php` covers these declarations and their negative cases.

### Runtime evaluation

Instead of declaring contracts by hand, an application can let Laramago read its
resolved authentication configuration from a local application load. The mode is
strictly opt-in: set `LARAMAGO_EVALUATE_RUNTIME=1` in the environment or add
`--evaluate-runtime` to the worker command in `extension-hosts`. Without the flag,
the runtime loader does not require `bootstrap/app.php` or evaluate configuration
files; the required Composer autoloader still runs. Verified native `env()` calls
with an explicit scalar default use its generalized type under Larastan's policy; calls without
a default retain `string|bool|null`. Static configuration arrays retain the broader
native environment possibilities.

```toml
[extension-hosts.laramago]
command = ["php", "-d", "display_errors=stderr", "vendor/ichinya/laramago/bin/laramago-worker.php", "vendor/autoload.php", ".", "--evaluate-runtime"]
```

When enabled, the worker requires the project's `bootstrap/app.php` and explicitly
runs only three configuration bootstrappers: environment variables,
configuration files and facade registration. It does not invoke the service-provider
registration or boot phases. Project bootstrap and configuration files still
execute PHP and may register providers, access a database or cause other side
effects. The worker does not prevent those project-defined effects. It discards
the loaded application after extracting the values. Authentication keeps only
literal string entries: the default guard,
each guard's provider and driver, and each provider's driver and model,
validated against the live autoloader. Literal `config()`, `Config::get()` and
`env()` reads additionally resolve to the machine's actual values — the general
scalar or array type, never a machine-dependent literal — after the static
source, with unchanged missing-key semantics and the exact framework
`env()` value conversion. Explicit `composer.json` contracts keep priority, any
load or extraction failure defers to the flag-off behavior, and the resolved
values describe the machine running the analysis — the same trade-off Larastan
makes when it boots the application. The local run must therefore match the
environment you want analyzed.

`php tests/evaluated-runtime.php` and `php tests/evaluated-config.php` cover
the flag semantics, deferral paths and contract priority.

### Selected query fields

Direct model calls can refine known physical columns:

```php
User::value('email');                         // string|null, for a required string column
User::pluck('email');                         // Support Collection<int, string>
User::firstOrFail(['email as contact']);      // User&object{contact: string}
```

`value` and `pluck` use the model's known read type, including supported
casts and accessors, but require a physical column. `value` retains null for a
missing row. Literal aliases in terminal `first`, `firstOrFail`, `sole` and `get`
calls belong only to that query result. They use raw schema types; renaming a
selected column does not apply the source attribute's Eloquent cast to the alias.
Aliases that collide with declared properties, casts, dates or accessors defer.

Literal `pluck` key columns use raw schema values and PHP array-key coercion;
casts and accessors affect the selected values, not the keys. Nullable keys retain
string because null becomes an empty-string key. Physical columns may be qualified
by the model's exact table name, including terminal aliases. Unknown or unrelated
tables and fields defer. `php tests/query-chains.php` checks these contracts.

Builder chains, SQL expressions and mutable
`select`/`addSelect`/`withCount`/`withSum` state remain outside this inference.
The extension does not validate SQL or invent a numeric type for `sum`.

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
unresolved collection factories defer to native analysis. Relation forwarding and `findOr()`
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
builders. Builder reads use Laravel's generic contracts and the explicitly
resolved custom collection types described above.

Parameter names, defaults and types come from the installed Eloquent or Query
Builder, including version-specific sorting directions. Aggregate return types
also use installed metadata: `count()` can retain `int<0, max>`, while `sum()`
stays `mixed` when that is Laravel's contract. Missing methods stay unknown.

Declared methods and PHPDoc retain priority. The standard query provider defers
for custom builders, query factories and magic dispatchers. Reads preserve known
collection contracts and defer for custom hydration, instance factories and
unresolved collections. A named scope that shadows a forwarded Query Builder
method prevents assigning the standard result. Scope, relation and explicit
macro support have the boundaries described above; runtime macro discovery and
other higher-order operations remain separate work.

No query, model constructor or application bootstrap runs during inference.
`first()` remains nullable, and the analyzer does not assume a row exists.
Unknown properties, collection property access, invalid writes and argument errors
remain visible. First-class method references remain callable.

### Query predicates

Standard model filters retain `Builder<Model>` through static, instance, inherited
and class-string calls. Query Builder predicates forwarded through an exact standard
Eloquent builder retain its model type as well:

```php
User::whereIn('id', [1, 2])->get();                   // Collection<int, User>
User::whereColumn('created_at', '<', 'updated_at');   // Builder<User>
User::whereDate('created_at', '2026-01-01')->first();  // User|null
User::whereMonth('created_at', 1)->firstOrFail();      // User
User::whereKey(1)->exists();                         // bool
```

| Family | Supported methods |
| --- | --- |
| Primary key | `whereKey`, `whereKeyNot` |
| Column comparisons | `whereColumn`, `orWhereColumn` |
| Membership | `whereIn`, `whereNotIn`, `orWhereIn`, `orWhereNotIn` |
| Null checks | `whereNull`, `whereNotNull`, `orWhereNull`, `orWhereNotNull` |
| Ranges | `whereBetween`, `whereNotBetween`, `orWhereBetween`, `orWhereNotBetween` |
| Dates and times | `whereDate`, `whereTime`, `whereDay`, `whereMonth`, `whereYear`, and their `orWhere` variants |

Parameter types, names, defaults and arity come from the installed framework.
For example, `whereColumn()` retains its two-operand shortcut and array form, and
`whereIn()` retains Laravel's `mixed` values contract; the extension does not invent
stricter argument types. Existing Mago checks on generic subquery arguments remain
visible. Direct Query Builder calls retain their native result.

Declared model methods and PHPDoc keep priority. Public Eloquent methods such as
`whereKey()` precede named scopes; scopes precede forwarding to Query Builder.
Mixin calls dispatched by Mago to Query Builder still use the original Eloquent
receiver when resolving a scope. Custom builders, query factories and dispatchers
retain the boundaries described above; unknown predicate names stay unknown.

Filters do not prove that rows exist or narrow model property types. In particular,
`whereNotNull('label')->firstOrFail()->label` retains the declared property contract,
and `whereKey(1)->first()` remains nullable. Queries and callbacks are never executed.

### Higher-order mapping over models

For standard Support and Eloquent collections of a single concrete model type,
`->map->method()` preserves the collection around the model method's result:

```php
User::get()->map->getRawOriginal()->all(); // array<int, array<string, mixed>>
$users->map->label();                     // Support Collection<TKey, string>
$users->map->replicate();                 // Eloquent Collection<TKey, User>
```

The latter two examples assume `label(): string` on the model and a standard
Eloquent collection of users. Keys are preserved. Eloquent keeps its collection
type when every returned value is a model; otherwise the result uses the common
Support collection type, which also accommodates an empty Eloquent collection.
Nullable method results remain nullable collection values. A completed `void`
method maps to `null`; a `never` method can only produce an empty collection.

Concrete native/PHPDoc return contracts, array shapes, and top-level `static` /
`$this` results are supported. Parameter-dependent PHPDoc branches use argument
types and declared defaults; uncertain or unpacked arguments retain both branches.
For example, `getRawOriginal()` returns attribute arrays, while a non-null or
unknown key retains Laravel's `mixed` value contract. Mago still checks argument
types, names, counts, visibility and unknown methods. Direct model calls and
first-class method references retain native behavior.

Support is limited to methods declared on model classes, their model ancestors,
or Laravel's Eloquent `Concerns` traits. Mago dispatches mixin calls to the method's
declaring class or trait, so unrelated application traits are currently deferred.
Custom collection/proxy classes, non-model or class-string items, mixed item types,
generic methods/classes, unresolved nested contextual types and higher-order
operations other than `map` defer to native analysis.
Existing explicit method/PHPDoc contracts retain priority. No model method,
collection callback, constructor, application bootstrap or database query executes.

`php tests/higher-order-map.php` runs real Mago scenarios with isolated declarations,
execution traps and concurrent workers in a path containing spaces. A comparison
with analyzer plugins disabled reproduces the native proxy-chain errors.

Property mapping such as `$users->map->name` also preserves keys and known model
property types. Physical properties and explicit read contracts retain priority;
known casts refine general model property reads. Scalar or nullable results produce a Support
collection; proven model results retain the Eloquent collection. Unknown or
inaccessible properties still produce diagnostics. Required array-shape and tuple
keys can also be mapped; all branches of an item union must have a known readable
value. Nullable input items, optional direct array keys and custom collection/proxy
classes defer. Model unions support higher-order methods when every branch has a
public zero-argument-compatible method; calls with arguments retain native behavior.
`php tests/collection-properties.php` covers the original property variant.

Standard collections also refine `min`, `max` and `sum` when item generics and a
literal selected property are known. Min/max retain null for an empty collection.
Sum uses `int|float` for proven numeric values, accounting for integer overflow and
the empty zero seed; arbitrary strings are not assumed numeric. This applies to
in-memory collection operations, independently of database aggregate semantics.
`php tests/collection-contracts.php` verifies these contracts and negative cases.

An immediate `$records->isEmpty() ? $fallback : $records->first()` branch can
preserve the nonnullable item type of a fresh native eager collection; `last()`
is also supported. The receiver must come from an exact native `new Collection`
or `new EloquentCollection` with a literal array, and the selected getter must
be evaluated first with no arguments. Installed constructor, item conversion,
predicate and getter contracts are verified. Nullable items, callbacks, defaults,
custom or lazy collections, hidden aliases and opaque or query-produced origins
defer. A base collection parameter can hold a subclass and is insufficient
evidence. The supported native implementation uses PHP 8.5 array helpers;
earlier PHP targets retain native diagnostics.
`php tests/nonempty-collection-results.php --integrated` compares native,
isolated and integrated analysis, including unguarded reads after guarded reads.

Higher-order `filter` and `reject` support known item properties and concrete model
methods on standard collections. They preserve input items, keys and the
Support/Eloquent collection class. Results may be empty; predicates do not narrow
individual item properties. Native argument diagnostics remain active, while
custom dispatch and unresolved contracts defer. `tests/higher-order-predicates.php`
compares these contracts against native Mago.

### Validated form input

Laramago resolves the complete result of
`Illuminate\Foundation\Http\FormRequest::validated()` as an array. A single
unconditional literal `rules()` array can refine known fields:

```php
// rules(): ['title' => 'required|string', 'note' => 'sometimes|nullable|string']
$request->validated();                   // open shape with required title and optional note
$request->validated('title');            // string
$request->validated('note');             // string|null
$request->validated('note', 'Untitled');  // string|null: present null stays null
```

Omitted keys and keys known to be `null` return the entire validated array.
When `rules()` is a literal map of string field names but its rule values are
unsupported, the complete result retains `array<string, mixed>`: the top-level
keys are known to be strings, while their values remain unknown. Dynamic rules
or mutating/unknown validation hooks retain `array<array-key, mixed>`. A literal map can
follow a native `$this->user()` read and a throwing type or strict-null guard; those
statements do not alter its field names. An `after()` hook that only checks
literal fields through native request readers and adds a validation error also
preserves the rule keys. Named arguments
and inherited form requests are supported. Unknown selected fields and unpacked
argument lists defer to native analysis. Native parameter checking remains active;
first-class method references remain callable.

Supported rules include `required`, `present`, `sometimes`, `nullable`, `filled`,
`string`, `array`, `numeric` and `boolean`. Numeric values retain
`int|float|string`; boolean values retain `bool|0|1|'0'|'1'`. The `integer` rule
does not establish an integer PHP representation and therefore stays mixed.
Optional array/boolean fields also retain string when blank strings can skip
ordinary validators. `bail`, `email` and numeric `min`/`max`/`size`
constraints are accepted without supplying a type themselves.

Literal dotted fields form nested open shapes when every intermediate parent has
an explicit `array` rule. Wildcards describe arrays with integer or string keys;
they do not guarantee a list, fixed index or nonempty result. Optional parents
remain optional. Laravel can omit even a required array parent when its optional
children produce no validated fields, so that possibility is retained.

Literal `Rule::in([...])` and string `in:` rules are recognized without executing
them. They do not cast the returned value or prove string type on their own.
`date_format:Y-m-d`, `url`, `uuid`, `ulid` and `alpha` establish string values;
other supported date formats, `alpha_num` and `alpha_dash` retain numeric input
representations. `php tests/request-nested.php` covers these rules and boundaries.

Explicit overrides, trait methods, PHPDoc contracts and more precise framework
return types retain priority. Requests that redeclare the validator property
also defer to native analysis.

This provider does not execute the request, validation or application bootstrap,
and never treats validation as a type cast. Dynamic rules, arbitrary rule objects,
unsupported rules, overlapping wildcard/literal paths and custom validation/preparation hooks
disable field inference. Shapes stay open; optional fields are not guaranteed to
exist. Scalar and array defaults are supported for optional selected fields;
callable/object defaults defer. Ordinary `input()` calls and magic request
properties retain native behavior.

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
composer check
```

This validates Composer metadata and runs every integration script registered in
`composer.json`. Individual scripts can also be run directly while developing.

`composer test:framework-native-contracts` runs the focused
[native framework and PHP contract checks](docs/native-framework-contracts.md),
including LF/CRLF source, changed framework declarations and retained diagnostics.

Some integration scripts leave generated workspaces in `var/compatibility-*`
for inspection. Completed workspaces can be deleted when their detailed reports
are no longer needed; subsequent runs create fresh directories. Other files in
`var`, including comparison reports and research scripts, should be assessed
separately. `var` and the local `.repowise` index are excluded from Git and package
archives.

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

`tests/migration-locals.php` checks scalar migration preparation, generated columns,
schema changes, nullable types, unknown properties and invalid writes through real
Mago. It also verifies conservative handling of references, captured variables,
dynamic calls and schema branches, without executing migrations or loading `.env`.

`tests/predicates.php` checks predicate chains, native argument validation, scope
precedence, installed signature changes and declaration priority through real Mago.
It includes a provider-disabled comparison and preserves errors for nullable results,
unknown properties and invalid writes, without application bootstrap or a database.

`tests/relation-methods.php` checks relationship generic inference, inherited and
late-static model targets, PHPDoc priority, factory overrides and installed contract
changes through real Mago. It compares native behavior with the provider disabled,
rereads changed source on a new run, and keeps bootstrap and constructor execution
traps in an isolated workspace without `.env` or a database.

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

Standard service helpers such as `app('cache')` and `resolve('session')` use installed framework aliases. See [framework helper contracts](docs/framework-helpers.md) for supported calls and override boundaries.

`str()` preserves the installed anonymous proxy and known `Str` method contracts; explicit `str(null)` returns `Stringable`. Standard `DB::transaction()` calls retain analyzed Closure results, including retry-aware nullability. Both are covered by [framework helper contracts](docs/framework-helpers.md).

[Permitted middleware references](docs/middleware-references.md) provide an optional exact literal-reference policy for native Route middleware calls.

[Final controller call contracts](docs/controller-call-contracts.md) optionally
check missing arguments and incompatible native parameter types for independently
asserted final controller calls after route binding and dependency resolution.
They preserve positional, nullable, default and variadic semantics without
bootstrapping Laravel or inferring effective routes and container state.

[Permitted assertViewIs identities](docs/assert-view-identity-policy.md) provide an optional literal-reference policy for native stored-view identity assertions.

Eloquent factory `$model` declarations accept a general `@var string` when the
literal model class agrees with the declared factory generic and the installed
parent contract. Current source and native metadata are checked without loading
the application. Conflicting models and unsafe storage changes retain diagnostics.

Bounded false operand compatibility follows PHPStan's handling of PHP ordered
comparisons and string concatenation. False plus an integer is accepted at
source-certified ordered comparisons with an integer peer; false plus a string
is accepted in concatenation with literal string peers. Other domains and all
unsafe arithmetic diagnostics retain their native behavior. Inferred types stay
unchanged, including the false alternatives.

[Closed configuration arguments](docs/closed-configuration-arguments.md) and [framework default date arguments](docs/default-date-arguments.md) adapt source-bound Laravel call contracts without loading the application. Dedicated native Mago regression matrices cover receiving declarations, one and three workers, and unsafe source or metadata replacements.

[Declared framework compatibility](docs/declared-framework-compatibility.md) supports Eloquent coalesce probes, certified nullable collection offsets and native Request, route and console postconditions. Native Mago regression matrices retain unsafe property, element and receiving-contract diagnostics.

[Repeated refreshed model values](docs/repeated-refresh-values.md) also recognize a native no-value argument after a second unconditional refresh on the same model receiver. Physical typed producer fields, primitive attribute readers, casts, schema and native declarations must establish the changed literal domain; aliases, references, unsaved receivers and genuine unreachable expressions keep their diagnostics. Run `php tests/repeated-refresh.php` for the native one/three-worker matrix and selected SDK cache controls.

[Bounded defensive compatibility](docs/defensive-boundary-compatibility.md) covers native array caller contracts, captured-state guards, configuration classification, quoted route keys and Inertia response checks. Installed Fortify defaults use verified static package binding declarations with explicit application bindings taking priority. All regression fixtures are analyzed through the production worker.

Laramago retains the selected model type in standard Eloquent `chunkById()` callbacks. See [selected chunk collection arguments](docs/selected-chunk-collection-arguments.md) and [driver extension forwarding](docs/driver-extension-forwarding.md) for the supported contracts and regression checks.

[Collection offset guards](docs/collection-offset-guards.md) cover defensive index reads and model checks after a standard Eloquent query and literal `keyBy('id')`. Native value types and unrelated diagnostics remain available.

Literal Request header arguments use the physical HeaderBag contract after a stable successful guard; see [the policy](docs/guarded-header-arguments.md).

Declared methods on standard Eloquent chunk models keep their concrete receiver contract through a read-only collection helper. See [selected chunk model methods](docs/selected-chunk-model-warning.md).

[Declared and captured postconditions](docs/declared-captured-postconditions.md) preserve closed assertion and callback evidence without changing native types.

[Defensive configuration member checks](docs/configuration-member-guards.md) retain classified validation checks while preserving business-operation Errors.

[XML cardinality guards](docs/xml-cardinality-guards.md) keep defensive singleton-node validation while preserving native value types and unrelated Errors.

[Session array-key copies](docs/session-array-key-arguments.md) follow the physical Laravel key normalization while retaining invalid aliases, refinements and receiving contracts.

[Model property arguments](docs/model-property-arguments.md) use current model declarations and primitive migration columns at verified receiving contracts, without loading the application or connecting to a database.

[Factory Faker contracts](docs/factory-faker-contracts.md) certify selected literal formatter values through current default declarations and registered-source override checks, without executing providers or the application.

[Possible callback tuples](docs/possible-callback-tuples.md) preserve closed positional writes from literal argument closures and physically typed captured inputs. Run `php -d memory_limit=512M tests/possible-tuples.php` for native one/three-worker and SDK restoration checks.

[Guarded local model tuples](docs/guarded-local-model-tuples.md) preserve a guarded concrete model from a standard Eloquent query when it is stored in a literal callback tuple. Run `php -d memory_limit=512M -d opcache.enable_cli=0 tests/guarded-local-model-tuples.php` for the native regression matrix.
