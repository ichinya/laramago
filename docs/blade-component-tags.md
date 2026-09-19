# Blade component tag candidates

`BladeComponentTagResolver` combines the snapshots returned by
`BladeClassComponentCatalog::components()`,
`BladeAnonymousComponentCatalog::components()`, and
`BladeComponentAliasCatalog::aliases()`. Pass those three results to its
constructor, including null when a catalog is unavailable. Pass the alias
catalog's `isComplete()` result as the fifth argument. It never parses or
executes a Blade template, boots Laravel, autoloads an application class, or
invokes a view finder.
Projects with no selected anonymous components or aliases should configure
those source catalogs with explicit empty root/file lists so they return `[]`
rather than null.

The optional `extra.laramago.blade-component-tags` configuration describes the
effective registrations represented by those snapshots:

```json
{
  "extra": {
    "laramago": {
      "blade-component-tags": {
        "complete": true,
        "default-class-namespace": "App\\View\\Components",
        "class-namespaces": { "ui": "App\\View\\Components\\Ui" },
        "anonymous-roots": [
          { "path": "resources/views/components", "mode": "default" },
          { "path": "resources/views/vendor/widgets", "mode": "path", "prefix": "widgets" }
        ]
      }
    }
  }
}
```

This configuration must agree with the class and anonymous source catalogs.
`default` means the application's effective `components` view directory;
`namespace` means a registered anonymous component namespace; and `path` means
a registered anonymous component path. For the latter two, `prefix` is the
effective registration prefix and must equal the corresponding anonymous
catalog root prefix. `class-namespaces` contains the effective
`Blade::componentNamespace()` prefix-to-namespace map.

`complete: true` is an explicit assertion by the project, and the alias catalog
must separately report complete. Together they assert that the selected
catalogs and these registrations cover all effective competing aliases, class
namespaces, class lookup candidates, anonymous paths, view namespaces and
customizations for the keys being queried. This includes the absence of dynamic
registration, non-component classes under the configured lookup namespaces,
and view-finder changes that could replace a selected target. The
resolver cannot verify that assertion from source. Omit it when registration
depends on runtime conditions. A null catalog also prevents resolution.

`candidates($key)` returns source-backed targets in Laravel's lookup order:
exact alias, registered class namespace, conventional class (including the
nested repeated-name fallback), anonymous view namespace (direct, `index`,
repeated-name), then registered anonymous paths in registration order. Each
path tries direct, `index`, and repeated-name views before the next path.
A registered path with a prefix can also match an unprefixed tag.
`resolve($key)` returns the first unique target only under the complete
assertion. It returns null for missing, ambiguous and unknown keys, including
an alias whose target is not a proven class in the selected class catalog.
Such an alias may point to a view and still blocks lower-priority guesses.
`resolveTag('<x-name>')` accepts only an isolated opening or closing x-tag;
it does not scan Blade documents or parse attributes.

This is a reusable metadata API. There is no Mago analyzer hook, Blade source
span mapping, completion provider, or diagnostic consumer yet. In particular,
null never means a component is invalid, and this API does not check required
props or suppress native Mago issues.
