# Explicit Livewire component registrations

`LivewireComponentCatalog` reads selected PHP source files without bootstrapping
Laravel, loading application classes, or running application code. It preserves
literal component names, class targets, optional Livewire 4 view paths, and the
effective registration's absolute file and line. Configure it in Composer metadata:

```json
{
  "extra": {
    "laramago": {
      "livewire-components": {
        "version": 3,
        "files": [
          { "file": "app/Providers/LivewireServiceProvider.php", "provider": "App\\Providers\\LivewireServiceProvider" }
        ],
        "complete": false
      }
    }
  }
}
```

`version` must be `3` or `4` and asserts the installed Livewire major version.
Livewire 3 accepts direct `Livewire::component($name, $class)` calls. Livewire 4
also accepts direct `Livewire::addComponent($name, $viewPath, $class)` calls.
Positional and named arguments can be mixed according to PHP's argument rules.
Names and view paths must be nonempty literal strings. Class targets may be
resolved `::class` constants or literal class-name strings. In Livewire 4,
`addComponent()` may give a class, a view path, or both.

A string in `files` selects top-level calls. An object selects the public,
zero-argument `boot()` method of a concrete class directly extending
`Illuminate\Support\ServiceProvider`; an empty `register()` is allowed.
Selected bodies may contain only direct static calls to `Livewire\Livewire`.
Imports are resolved by syntax. Unsupported calls, dynamic arguments, other
statements, unreadable files, invalid paths, and unsupported provider shapes
make the entire catalog unknown. The file list asserts activation and order;
later registrations replace earlier names and provenance.

`components(): ?array` returns the effective name-to-registration map, or
`null` when it cannot be established. Each entry has `class`, `viewPath`,
`file`, and `line` fields. `component($name): ?array` retrieves one entry.
`contains($name): ?bool` returns `true` for a selected name and `null` for an
unselected name unless `complete: true` asserts that the selected files cover
the entire effective explicit registration map. Under that assertion, an
unselected name returns `false`. Names are exact and case-sensitive.

Even a complete explicit registration map does not prove that a Livewire
component name is invalid: Livewire may resolve conventional classes, view
files, or user-defined missing-component resolvers. The catalog does not
resolve targets, verify class ancestry or file existence, map conventional
names, inspect component properties, or diagnose PHP or Blade references.
Create a new instance after changing sources or configuration; an instance is
a source snapshot.

The method signatures and registration behavior follow the official
[Livewire 3 manager](https://github.com/livewire/livewire/blob/3.x/src/LivewireManager.php),
[Livewire 3 registry](https://github.com/livewire/livewire/blob/3.x/src/Mechanisms/ComponentRegistry.php),
and [Livewire 4 manager](https://github.com/livewire/livewire/blob/4.x/src/LivewireManager.php).
