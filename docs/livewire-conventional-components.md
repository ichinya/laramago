# Conventional Livewire component metadata

`LivewireConventionalComponentCatalog` reads selected class and Blade files as a
source snapshot. Configure the effective Livewire major version and every root
explicitly in the application's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "livewire-conventional": {
        "version": 3,
        "class-roots": [{"namespace": "App\\Livewire", "path": "app/Livewire"}],
        "view-roots": [{"path": "resources/views"}],
        "conventional-view-prefix": "livewire"
      }
    }
  }
}
```

Version 3 accepts one asserted effective class namespace. `Admin/Index.php`
maps to `admin`; class segments use Laravel `Str::kebab` naming. When the class
and its application ancestors have no `render()` method, the configured view
prefix yields a candidate such as `livewire.admin`. A direct sole
`return view('other.admin')` selects that literal instead. A dynamic return,
branches, inherited custom `render()`, or absent matching view yields a null
`viewPath`.

Version 4 also accepts `component-view-roots`, each with `path` and optional
`prefix`, for ordinary and bolt-prefixed single-file Blade components. An
optional `prefix` on a class root models an explicitly active named class
namespace, such as `admin::`. Multiple class roots are allowed for asserted
version 4 locations. Version 4 class views require a direct literal render
return; no default class view is assumed. Multi-file formats and class `Index`
aliases remain outside this catalog.

`components()` returns null for unconfigured, invalid, unreadable or ambiguous
roots. Otherwise it maps exact component names to `class`, `viewPath`, `file`
and one-based declaration `line`. `component(name)` returns an entry or null.
`contains(name)` returns true for known names and null otherwise. Absence never
proves a missing runtime component: explicit aliases, package providers and
custom resolvers can add names. An explicit registration catalog can be
composed separately, with its effective precedence asserted by the caller.
The catalog does not execute application files, compile Blade, bootstrap
Laravel or inspect runtime Livewire state.

The version-specific boundaries follow [Livewire 3's registry naming and
`Index` handling](https://github.com/livewire/livewire/blob/3.x/src/Mechanisms/ComponentRegistry.php),
[Livewire 3's omitted-render convention](https://livewire.laravel.com/docs/3.x/computed-properties),
and [Livewire 4's documented component formats and class locations](https://livewire.laravel.com/docs/4.x/components).
