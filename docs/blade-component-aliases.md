# Explicit Blade component aliases

`BladeComponentAliasCatalog` reads selected literal `Blade::component()` and
`Blade::components()` registrations as source text. It does not bootstrap Laravel,
execute application PHP, load application classes or use the runtime Blade
registry. Configure the selected effective source files in Composer metadata:

```json
{
  "extra": {
    "laramago": {
      "blade-component-aliases": {
        "files": [
          { "file": "app/Providers/AppServiceProvider.php", "provider": "App\\Providers\\AppServiceProvider" }
        ],
        "complete": false
      }
    }
  }
}
```

An object selects the public zero-argument `boot()` method of a concrete class
directly extending `Illuminate\Support\ServiceProvider`. A string entry selects
a PHP file containing top-level registrations. An empty public zero-argument
`register()` method is also accepted in the selected provider. Imports, `::class`
constants, literal target strings, named arguments, and literal prefixes are supported. Selected
bodies may contain only direct static calls to the native `Blade` facade:

```php
use Illuminate\Support\Facades\Blade;

Blade::component(\App\View\Components\Alert::class, 'notice');
Blade::component('components.alert', 'view-alert');
Blade::components(['badge' => \App\View\Components\Badge::class], 'ui');
```

The three entries yield `notice`, `view-alert` and `ui-badge`. The `components()` form requires
literal string aliases and backslash-containing target values: Laravel forwards each entry
through `component()` and swaps the arguments when the value contains a
backslash. Unnamed aliases, numeric keys, dynamic expressions, other statements,
unsupported provider shapes, unreadable files and paths outside the project make
the whole catalog unknown. Later selected registrations replace earlier aliases.

Selecting sources asserts that they execute and are effective in this order; syntax
alone cannot prove provider activation or package registration order. The optional
`complete: true` separately asserts that the selected map is the entire effective
class-component alias registry, including framework and package registrations.
Without that assertion, an absent alias remains unknown. Even a complete alias
map says nothing about convention-based classes, anonymous components or other
tag-resolution paths.

`aliases(): ?array` returns exact alias-to-registered-target pairs or null for an unknown
catalog. `target(string): ?string` looks up a known alias. `contains(string):
?bool` returns false for absence only in a complete catalog; `isComplete(): bool`
reports that assertion. Names are exact and case-sensitive. A target may name a
class or a view; Laravel's tag compiler checks these at runtime. The catalog does
not verify either target, convert tag names, resolve views or infer props.
Create a new instance after source or configuration changes; each instance is a
source snapshot.
