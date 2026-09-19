# Blade class component declarations

`BladeClassComponentCatalog` reads explicitly selected namespace/path mappings
without loading Composer's application autoloader, booting Laravel, constructing
components, reflecting application classes, or evaluating PHP. For example:

```json
{
  "extra": {
    "laramago": {
      "blade-class-components": {
        "roots": [
          { "namespace": "App\\View\\Components", "path": "app/View/Components" }
        ]
      }
    }
  }
}
```

Paths are project-relative directories contained in the project after resolving
links. Namespaces have no leading or trailing slash. Each class must match its
PSR-4-shaped path and namespace exactly. Additional roots may supply application
base classes. The catalog does not infer the application's effective namespace
or app path, which Laravel can configure at runtime.

`components()` returns a list of `BladeClassComponent` records containing the
fully qualified class, source path, effective public constructor parameter
declarations, and application-declared public non-static properties. Concrete
classes must have an AST-proven chain to `Illuminate\View\Component`. Abstract
bases contribute metadata but are not returned as components. Child constructors
replace parent signatures; inherited promoted properties remain property
declarations even when a child constructor does not initialize them.

Parameter metadata preserves names, declared PHP types, default-presence,
variadic and promotion flags, and the declaring class. Property metadata preserves
names, declared PHP types and the declaring class. Imported types resolve
lexically; `self`, `parent` and `static` remain declaration syntax with their
declaring context. An absent type stays null. PHPDoc remains authoritative for
Mago; this catalog neither infers types nor replaces any native/PHPDoc contract.

Null `components()` means missing/invalid configuration, unreadable source,
parse failure or duplicate selected declarations. Unresolved ancestry, conditional
classes and path mismatches are omitted. Traits anywhere in a proven hierarchy
make both member lists unknown (null); non-public constructors make the parameter
list unknown; property hooks make the property list unknown. Framework base
properties are outside these application declaration lists. No returned list
asserts global completeness or supports missing-name diagnostics.

These are source declarations, not effective Blade data: uninitialized typed
properties may fail when read; `$except`, custom `data()`/`shouldIgnore()` behavior,
public-method variables, runtime mutation and custom component resolvers are not
evaluated. `hasDefault` records syntax and is not a required-prop decision.
Render methods and view helpers are never executed or resolved. Anonymous
components, aliases, registration, tag conversion/resolution, view links and
required-prop diagnostics are separate work. Each instance is a snapshot; create
a new catalog after source/configuration changes. There is no analyzer hook or
diagnostic attached to this reusable metadata API.
