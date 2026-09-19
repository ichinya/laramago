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
names, or inspect component properties. Create a new instance after changing
sources or configuration; an instance is a source snapshot.

`BladeLivewireReferenceParser` reads one original Blade buffer, without
compiling it, and reports literal `@livewire('name')` and `<livewire:name>`
references with original byte spans. It skips comments, escaped directives and
tags, verbatim blocks, Blade echoes, PHP, dynamic names and the dynamic
`<livewire:is>` form. Malformed or oversized buffers return `null`; a partial
scan reports `complete: false`. The parser makes no missing-name claim.

`BladeSourceDiagnostics::livewire()` can report `blade-missing-livewire-component`
for a literal name only when **all** of these are independently established:

1. The selected explicit registration map is complete (`complete: true`).
2. A separately supplied list of conventional names is complete for the
   installed Livewire version, including its configured class and view lookup.
3. The caller asserts that the combined effective resolver has no additional
   aliases, missing-component resolvers, overrides, or runtime mutation.
4. The caller asserts native version-specific Blade reference semantics for
   the selected source, with no custom compiler or precompiler replacement.

An incomplete name universe returns no missing-name findings, even when the
source parser extracts positive references. The diagnostics are a standalone
source-only result using `BladeSourceDocument`; Mago does not currently publish
issues for foreign Blade files. PHP render calls, dynamic names, class/view
target validation and Livewire mount contracts remain outside this check.
Bare and backslash-containing strings remain positive references but cannot
receive missing-name diagnostics: Livewire can treat them as direct class names,
and this resolver contract does not prove class absence. The check warns only
on absent names containing a dot, colon or hyphen.

The method signatures and registration behavior follow the official
[Livewire 3 manager](https://github.com/livewire/livewire/blob/3.x/src/LivewireManager.php),
[Livewire 3 registry](https://github.com/livewire/livewire/blob/3.x/src/Mechanisms/ComponentRegistry.php),
and [Livewire 4 manager](https://github.com/livewire/livewire/blob/4.x/src/LivewireManager.php).
