# ichinya/laramago

A Composer package with a Laravel preset for the native Mago CLI.

```sh
composer config repositories.laramago vcs https://github.com/ichinya/laramago
composer require --dev ichinya/laramago:0.0.12
vendor/bin/mago lint
```

During installation, Composer asks for permission to run the `ichinya/laramago`
plugin. Once allowed, the plugin creates `mago.dist.json` in the application root.
The `carthage-software/mago` dependency provides `vendor/bin/mago`; this package
uses that executable directly, without a wrapper or Laravel service provider.

Version `0.0.12` expands static Eloquent, request, collection, authentication and
HTTP test support, with explicit catalogs for container bindings and macros.
Native declarations and PHPDoc contracts retain priority.
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

Declared methods keep their native behavior. Custom query factories and magic
dispatchers defer when their behavior is not statically known. Explicit custom
builders, local scopes, and additional bounded integrations are described below.
The package does not generate overlays or
replace Larastan. `examples/compatibility.toml` is an optional fragment with
targeted suppressions for your `[analyzer]` section.

The worker uses Mago's bundled PHP SDK and the application's Composer autoloader.
It does not bootstrap Laravel or connect to a database. The `php` executable must
be on PATH; a project may override the worker command with a specific executable.

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
An untyped scope result stays unknown. Generic scope methods and custom query or
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

Native PHP properties and existing `@property`, `@property-read`, and
`@property-write` contracts retain priority. Casts refine schema types and preserve
schema nullability; without a known column, cast-derived attributes include `null`.
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
contracts follow inherited model receivers. Method templates, generic ancestor
remapping, unresolved callable containers and reference signatures defer.
`php tests/builder-generics.php` checks this specialization and its negative cases.
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

### Additional static integrations

These integrations inspect declarations and syntax without booting the application.
Unknown dynamic behavior keeps native Mago diagnostics and types.
The [coverage matrix](docs/static-analysis-support.md) lists the implemented and
deferred portions of these integrations.

| Area | Supported subset | Boundaries |
| --- | --- | --- |
| Relationship methods | Standard factories, trait declarations and explicit native pivot modifiers retain related/declaring/intermediate model types | Explicit PHPDoc wins; unresolved model/trait generics, dynamic MorphTo and custom factories defer |
| Relation results | Standard relation forwarding for `first`, `firstOrFail`, `sole`, `get`, sorting and selected aggregates | Native declarations win; custom relations, `MorphTo` and custom related builders defer |
| Selected query fields | Direct model `value`/`pluck`, including literal keys and exact-table qualification; terminal queries preserve literal aliases on their own result | Stateful builder projections, expressions, `withCount`/`withSum` and unknown schema defer; `sum` keeps its installed contract |
| Relationship callbacks | Literal dotted paths for the four `whereHas`/`whereDoesntHave` variants receive `Builder<Related>`; `withWhereHas` receives a Builder/Relation union | Uses authoritative PHPDoc or supported relation bodies; dynamic paths and custom dispatch defer |
| Relation validation | Warns when a referenced existing method explicitly returns a known non-relation class, including nested paths | Missing methods may be dynamically registered and are not reported |
| Authentication | Nullable default models from literal config or explicit Composer auth contracts; standard guards and explicitly declared custom guard classes | Environment values are never evaluated; standard selected guard users retain native `Authenticatable|null`; custom guards retain their own user contracts |
| Collection operations | Standard collection null filtering, model/required-shape higher-order maps, known union branches and literal-property aggregates | Custom subclasses, unsafe branches, callback/key filtering and union method calls with arguments defer; see the mapping contract below |
| HTTP test assertions | Typed Laravel and optional Laratesto callbacks, nested fluent scopes, standard Inertia page envelopes and flash assertions | Known installed declarations required; custom contracts/macros win; selected prop and JSON values remain unknown |
| Facades | Concrete roots and public service signatures from class-string accessors or explicit static binding catalogs | Uncataloged aliases, runtime binding discovery, generic/reference contracts and custom dispatch defer; declared methods and PHPDoc win |
| Configuration | Literal helper and native `Config::get` reads from static configuration arrays, including shapes and known defaults | Arbitrary repository instances, environment evaluation, runtime mutations, package-merged defaults and missing-key warnings defer |
| Translation strings | Known PHP/JSON strings with explicit locales or literal `app.locale`, respecting JSON precedence | Dynamic locales, custom paths/loaders and missing-reference diagnostics defer; native `view()` typing is retained |
| Route parameters | Duplicate placeholders in literal native Router, lexically resolved native Route facade and Route `setUri()` calls | Runtime aliases, dynamic URIs, custom dispatch, middleware and named-route registries defer; see [route validation](docs/route-parameters.md) |
| Macros | Typed closures, static callable arrays and invokable objects in explicit catalogs, including known boolean/ordered hasMacro guards | Catalog activation/order/completeness are user guarantees; unknown conditions, dynamic, generic and reference contracts defer; see [macro catalogs](docs/macros.md) |

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

Omitted, null or falsy translation locales use only a literal `config/app.php`
locale and one conventional language root. This models the configured initial
locale; runtime locale changes and custom translation-loader paths require
additional application contracts.

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

Explicit return PHPDoc keeps priority, including an intentionally broad contract.
The installed relationship templates and factory declarations must match the
supported Laravel contracts. Custom factories, relation constructors and related
model resolvers defer. Native visibility and argument checks remain active, as do
unknown-property, invalid-assignment and nullable-result diagnostics.

Application-trait declarations are supported, including nested imports, aliases
and inheritance. A trait's `self::class` refers to its importing class, while
`static::class` follows the actual receiver. Native `using(Pivot::class)` and
`as('accessor')` modifiers preserve explicit pivot/accessor arguments for verified
four-template relation layouts. Morph relations require a MorphPivot subclass.

Unresolved model/trait generics, dynamic targets or keys, branches, locals,
arbitrary chained modifiers, untyped/nullable method declarations and dynamic
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
User::whereDate('created_at', '2026-01-01')->first();  // User|null
User::whereMonth('created_at', 1)->firstOrFail();      // User
User::whereKey(1)->exists();                         // bool
```

| Family | Supported methods |
| --- | --- |
| Primary key | `whereKey`, `whereKeyNot` |
| Membership | `whereIn`, `whereNotIn`, `orWhereIn`, `orWhereNotIn` |
| Null checks | `whereNull`, `whereNotNull`, `orWhereNull`, `orWhereNotNull` |
| Ranges | `whereBetween`, `whereNotBetween`, `orWhereBetween`, `orWhereNotBetween` |
| Dates and times | `whereDate`, `whereTime`, `whereDay`, `whereMonth`, `whereYear`, and their `orWhere` variants |

Parameter types, names, defaults and arity come from the installed framework.
For example, `whereIn()` retains Laravel's `mixed` values contract; the extension
does not invent stricter argument types. Existing Mago checks on generic subquery
arguments remain visible. Direct Query Builder calls retain their native result.

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
property types. Public native/PHPDoc contracts take precedence over inferred
attributes, casts and accessors. Scalar or nullable results produce a Support
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
Without supported rules, this retains `array<array-key, mixed>`. Named arguments
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
