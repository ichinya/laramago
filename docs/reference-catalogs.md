# Complete reference catalogs

Missing literal reference diagnostics are disabled by default. Applications can explicitly
assert that the following catalogs completely describe their runtime lookup behavior:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "views": {"complete": true, "paths": ["resources/views", "custom-views"]},
        "translations": {"complete": true, "path": "lang", "locales": {"en": ["en", "fr"]}},
        "inertia-pages": {
          "complete": true,
          "paths": ["resources/js/Pages", "resources/frontend pages"],
          "extensions": ["vue", "tsx"]
        }
      }
    }
  }
}
```

This is a user-supplied closed-world assertion, not an automatically discovered Laravel
configuration. Do not enable it when additional provider paths, custom loaders/finders,
custom view extensions, runtime translation injection, or runtime fallback changes can
resolve references outside these catalogs. Paths are relative to the application root;
configured root directories must exist. Translation lists explicitly describe the full
PHP fallback chain, beginning with the requested locale. The requested locale's JSON
catalog takes precedence. This does not change inferred return types or native diagnostics.

The optional `inertia-pages` catalog discovers files recursively from the exact configured
roots and extensions without loading Laravel or executing JavaScript or TypeScript. Page
names are extensionless paths relative to their configured root, use `/` separators, and
remain case-sensitive on every host. Root order and duplicate names are preserved so later
consumers can distinguish ambiguous pages. Leading dots on extensions are accepted and
normalized; extension matching itself is case-sensitive. A valid catalog without
`complete: true` still exposes known pages, but an absent name remains unknown. Missing
roots, unsafe paths, unreadable directories, linked entries and malformed paths or
extensions make the Inertia catalog unknown. Mark the catalog complete only when the
listed roots and extensions describe every page that the application can resolve.

Set `"unique": true` inside `inertia-pages` only when your frontend resolver requires
each page name to identify one file across the configured roots and extensions. A native
render call then reports `ichinya/laramago/laramago-ambiguous-inertia-page` if its exact
page name matches distinct files. Repeated roots that point to the same file count once.
The assertion works with incomplete catalogs because the collision is already known.
Without it, duplicate names remain valid: a resolver may deliberately select the first
matching root or extension. The analyzer does not execute or model frontend resolution.

`ReferenceCatalogs::inertiaPageProps($name)` can read literal prop names from a known
`.vue` page's inline `<script setup>` block. It recognizes `defineProps(['name'])`,
`defineProps({ name: String })`, and an inline type shape such as
`defineProps<{ name: string }>()` as a top-level statement or direct variable
initializer. The result includes `names` in source order and a
`complete` flag. Dynamic array entries, object spreads and computed keys retain any
known names with `complete: false`; imported or composed type shapes remain unknown.
Nested calls such as `withDefaults(defineProps<...>(), ...)` also remain unknown.
Pages with custom top-level SFC blocks remain unknown because their contents may mimic
script tags. Sources above 512 KiB or containing binary NUL bytes remain unknown.
Only a page name resolving to one physical Vue file is inspected. The file is read on
first request and cached for the catalog instance. Comments, strings, ordinary scripts,
and frontend code are never treated as declarations or executed. This names-only
metadata does not assert which props are required or validate their values.

The optional `public-assets` catalog indexes files under explicit project-relative
public roots, including custom directories and paths with spaces:

```json
"public-assets": {"complete": true, "paths": ["public", "custom public"]}
```

Names are paths relative to each root, with `/` separators; root order and duplicate
names are retained. The index initializes on first use and can be reset between analyses.
An omitted or false `complete` value exposes known files but cannot prove a missing
reference. Set `complete: true` only when these roots describe every locally resolvable
public file for the application, including any build output. A conventional `public`
directory is not assumed to be complete: URL generators can use remote asset hosts,
custom serving rules or generated manifest paths. The index does not interpret Mix or
Vite manifests, inspect remote URLs or infer runtime asset closure. Absolute URLs,
query strings, fragments, percent-encoded paths and dot segments are left unknown.
Missing roots, linked entries, unreadable directories or files, and scans beyond 4,096
entries or 32 directory levels also leave the catalog unknown. No application bootstrap,
environment configuration, JavaScript or PHP asset file is executed.

The `view` helper checks conventional dotted or slash-separated literal names against
Blade/PHP/CSS/HTML files. The `trans` and `__` helpers check literal PHP keys and JSON phrases only
with an explicit literal locale listed in the contract. Static JSON overrides, nested
PHP arrays, duplicate literal keys and fallback catalogs are respected. Namespace-imported
and fully qualified calls retain framework identity checks; local shadow functions defer.
Missing entries report `ichinya/laramago/laramago-missing-view` or
`ichinya/laramago/laramago-missing-translation` warnings.

Literal component names passed to the installed native `Inertia::render` facade,
`Inertia\ResponseFactory::render`, truthy literal `inertia()` helper calls, or
native `Route::inertia` registrations are checked against a complete `inertia-pages`
catalog. Missing pages report `ichinya/laramago/laramago-missing-inertia-page`.
Direct factory subclasses, facade subclasses, custom package replacements, dynamic
component expressions, argument unpacking and incomplete catalogs defer.

Helper and route checks verify the audited Inertia 3.x forwarding contracts.
Route checks additionally require `"route-macro-active": true` in `inertia-pages`:
this asserts that the native provider macro is active, which package installation
alone cannot prove. Configured router bindings and application macro replacements
disable route checks. The helper's empty string and `"0"` factory branches remain native.

Unconfigured package namespaces, arbitrary dynamic keys/locales, implicit locale mutation, first-class
callables, argument unpacking, unsafe/dynamic catalogs, custom helpers and unsupported
catalog shapes defer. Other facades, Blade directives, `trans_choice`
and other view factory methods are not covered. Catalogs are parsed without executing PHP.

Native `View::make()` and exact `Illuminate\View\Factory::make()` receivers also
check literal view names with the complete `views` contract. Positional and named
arguments are supported. The native factory lookup and name-normalization methods
must match the audited Laravel implementation; facade forwarding must remain native.
Configured view/finder service replacements disable these checks. Subclasses,
interface-typed factories, dynamic/unpacked arguments and customized native methods
defer. `exists()` is an intentional existence query; `first()` has separate fallback checks below.
`file()` accepts a filesystem path and does not produce missing-view warnings.
Conditional rendering has separate selected-branch checks below. This contract also excludes
runtime finder replacement, name-cache remapping and dynamically added view paths.

Native `Response::view()`, exact `Illuminate\Routing\ResponseFactory::view()` and
zero-argument `response()->view()` calls also check a literal string view name. Named
`view`, `data`, `status` and `headers` arguments retain their native positions.
Response and View factory forwarding, normalization, response helpers and facade
dispatch must match the audited native declarations. Known response/view/finder
bindings, subclasses, arbitrary response contract receivers, changed declarations,
array view candidates, dynamic values and unpacked arguments defer. Array candidates
use Laravel's `first()` lookup and are not individually required views. The complete
view catalog remains an assertion of the actual application's finder behavior.

Native `View::first()` and exact `Illuminate\View\Factory::first()` receivers report
`ichinya/laramago/laramago-missing-first-view` only when every candidate in a literal
unkeyed list is provably missing from the explicitly complete views catalog. Empty
lists also warn. Any existing candidate, unknown/dynamic name, unconfigured package namespace,
keyed entry, unpacking or reference defers; a missing candidate before an existing
fallback is valid. An existing view named `0` conservatively defers even though the
native selection subsequently rejects its falsey name.

This check audits `first()`, `exists()`, `Arr::first()`, `Arr::from()` and the global
`value()` helper in addition to the native make/normalization contract. The audited
Arr implementation uses `array_find_key`, so this check requires Mago's selected PHP
target to be at least 8.4; lower targets and older or changed Arr implementations
defer. Namespaced helper overrides, custom factory/facade contracts and configured
finder/view replacements also defer. Engine creation is never reached when every
candidate is missing. No application, view, callback or framework method is executed.

Native facade and exact concrete factory `renderWhen(true, "name")` and
`renderUnless(false, "name")` calls check the selected literal view. Explicit data
and merge data must be literal arrays; arbitrary Arrayable conversions are not
executed. `renderEach("name", [1], "item")` checks the item view for a nonempty
literal array. With an empty literal array, only an explicit literal `empty` view
is required; the default and `raw|` text are not view references. Named arguments
retain native positions, and missing required arguments retain native diagnostics.
Dynamic conditions/data, array unpacking, factory subclasses/interface receivers,
custom service bindings, modified native methods and shadowed branch functions defer.
Neither views nor application bootstrap are executed.

The optional `views.namespaces` map selects complete effective hint paths for each
listed package namespace, for example:

```json
{"complete": true, "paths": ["resources/views"], "namespaces": {
  "billing": ["resources/views/vendor/billing", "packages/billing/resources/views"]
}}
```

Each list must contain all effective hint paths in their runtime order, including
application overrides. Only listed namespaces are asserted complete: an unknown
namespace still defers even when ordinary view paths are complete. No service
provider is loaded and namespace registration is not inferred. Existing, readable,
project-relative directories are required; paths with spaces work. Malformed maps,
missing roots, root escapes, unreadable lookup directories and linked lookup entries
defer. Case-only file mismatches defer because filesystem case behavior varies.
Literal `billing::invoice` names use the same guarded helper, View and Response
entry points as ordinary view names, with the default Blade/PHP/CSS/HTML extensions.
This requires the selected native lookup contract, without later namespace changes,
custom extensions, cached-name remapping or custom finders. Templates are never executed.

Missing single-view diagnostics from `view()`, `View::make()` and response view
entry points can include up to three nearby conventional view names and their
project-relative declaration paths. Suggestions use only the explicitly configured
ordinary view paths, never execute templates, and do not change diagnostic severity
or add edits. Namespaced catalogs, conditional rendering and `first()` lists currently
have no suggestions. The optional index is lazy, skips symbolic links, stays within
the project, and stops after 4,096 entries or 32 directory levels. Unreadable or
oversized catalogs omit suggestions without suppressing a missing-view diagnostic.
Suggestions use edit distance at most two (one for names shorter than five characters),
then lexical order. Blade declarations remain textual notes rather than foreign-file
Mago annotations or editor navigation targets.

Native `Lang::get()` and exact `Illuminate\Translation\Translator::get()` receivers
also check literal PHP keys and JSON phrases with an explicit, non-empty locale in the complete
translation catalog. Positional and named arguments work; `fallback` must be omitted
or literal `true`, so the configured PHP fallback chain applies. Literal `false`,
dynamic fallback selection, falsey locales, subclass receivers and argument unpacking
defer. `has()` and `hasForLocale()` are existence queries and never produce required-key
warnings. This does not narrow return types.

The installed Translator, NamespacedItemResolver and Lang declarations must match the
audited native implementation, including PHPDoc. Facade forwarding and array lookup
methods are checked as well; known translator/loader replacements and relevant
namespace function overrides disable the check. The explicit catalog contract excludes
runtime loaded-line changes, parsed-key remapping, custom missing-key callbacks,
replacement/stringable callbacks that change lookup
state, and additional loader paths. Different framework declarations conservatively defer.

Literal JSON phrase references in `__()` and `trans()` use an explicit locale and
complete translation catalog. Exact requested-locale JSON keys take priority over
PHP group keys, including dotted or namespace-shaped JSON keys. A missing phrase
also checks PHP groups in the configured locale chain; fallback-locale JSON files
are not consulted by native `Translator::get`. A whole PHP group can return an
array and is not reported as a missing phrase. Existing JSON keys with null,
false, zero, empty-string or array values conservatively defer; this check does
not assert a useful translated value. Numeric JSON object keys defer because
native file loading renumbers integer keys. Malformed/unreadable JSON, dynamic
locales and filesystem-ambiguous group names defer. Catalog files are never run.

## Package translation namespace hints

A complete `translations` catalog may declare an optional final namespace map:

```json
"namespaces": {"billing": "packages/billing/lang"}
```

Each value is the single project-relative hint directory selected by Laravel's native
`FileLoader::addNamespace()`. This map is an explicit assertion, not provider discovery.
Unknown namespaces remain unknown. Literal `billing::messages.key` references check
requested-locale JSON first, then the package hint and application
`<translations.path>/vendor/billing/<locale>/messages.php` for every configured PHP
fallback locale. No package JSON directory is inferred. Configured hint directories
must exist and resolve inside the application; paths with spaces are supported.

The check proves absence only when both package and override catalogs lack the key.
Any possible declaration suppresses a warning: recursive overrides which replace an
existing parent therefore conservatively defer instead of claiming the key survives.
Dynamic arrays, dotted literal array keys, malformed/unreadable files, case-ambiguous
paths and nested symlinks also defer. No provider, translation file or bootstrap runs.
Missing names reuse `laramago-missing-translation`; custom-loader and runtime-injection
exclusions of the complete translations contract still apply.

### Translation diagnostic provenance

Missing-translation warnings include the requested locale's project-relative JSON
lookup path and the configured PHP locale chain, with its `composer.json` setting.
These notes explain the explicit catalog contract; they do not assert that a file
exists or identify the locale actually selected at runtime. Fallback-locale JSON
is not part of the `get` lookup chain. Existing success, uncertain catalog, custom
translator and dynamic fallback cases retain their existing behavior. No language
file or application bootstrap is executed to produce these notes.

Explicit [required Inertia prop contracts](inertia-required-props.md) can check closed literal render props when shared names and subsequent response mutations are covered by completeness assertions.

Explicit per-page literal frontend JSON type expectations can be checked on native render calls; see [Inertia literal prop type contracts](inertia-prop-types.md).

The complete Inertia catalog also checks literal expected names in native `Inertia\Testing\AssertableInertia::component()` assertions, including `assertInertia()` callbacks. Explicit `shouldExist: false`, dynamic existence options, custom receivers and modified package methods defer. The audited Inertia 2.x/3.x method body is required. This checks the catalog contract, not response contents or test outcomes.
