# Static Gate definition metadata

`GateDefinitionCatalog` indexes literal `Gate::define` registrations without loading Laravel.
Configure `extra.laramago.gate-definitions` in the application's composer.json:

```json
{
  "files": [
    "bootstrap/gates.php",
    {"file": "app/Providers/AbilityProvider.php", "provider": "App\\Providers\\AbilityProvider"}
  ],
  "complete": false
}
```

Selection explicitly asserts these sources are active, in this order, with standard Laravel Gate semantics. It does not discover provider activation, bootstrap the application, or validate custom Gate bindings. A plain file permits only imports, strict_types declarations, and unconditional fully resolved `Illuminate\Support\Facades\Gate::define` calls. A provider selector permits a direct `Illuminate\Support\ServiceProvider` subclass with a single public nonstatic parameterless `boot` method containing those calls. Additional members, inheritance through application classes, traits, attributes and dynamic control flow are unsupported. Provider namespaces and imports are resolved statically. Neither ordinary app/provider directories nor Laravel defaults are scanned.

Ability names must be literal strings. Callback syntax may be a closure, arrow function, string, or unkeyed pair of a literal class string/class constant and literal method string. Registration and callback AST nodes retain source locations, native type declarations and PHPDoc; callable targets are never loaded, reflected upon or checked for existence. Callback validity and resulting types remain native analyzer responsibilities. This is source metadata, not a replacement for native callable contracts. Closure bodies are not executed or interpreted. Duplicate abilities use the last selected registration.

`definitions()` returns the declaration map or null for absent/unsupported configuration. `definition($ability)` returns callback/registration AST, file and line. `contains($ability)` is true for a recorded declaration and null for an unknown name unless `complete: true` explicitly asserts the selected files cover every effective explicit definition. Any unsupported file or statement invalidates the entire catalog, including completeness; paths must remain inside the project. An explicitly empty complete list proves no explicit definitions.

Completeness never means complete authorization coverage. Policies, policy discovery, before callbacks and other Gate behavior can handle names absent from this catalog. No missing-ability diagnostics, return-type overrides, policy mappings or permission conclusions are added. Resource registrations, enum names, container-selected Gate instances, provider property maps and conditional registration stay outside this catalog.

The reference Laravel `Gate::define` stores callable or string callbacks and overwrites an existing ability; its `abilities()` returns the stored map. The official LSP auth collector obtains that map at runtime and reflects its callbacks. This catalog adapts declaration provenance only and does not reproduce that runtime collector.
