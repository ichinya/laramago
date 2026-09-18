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

The `view` helper checks conventional dotted or slash-separated literal names against
Blade/PHP/CSS/HTML files. The `trans` and `__` helpers check literal dotted PHP keys only
with an explicit literal locale listed in the contract. Static JSON overrides, nested
PHP arrays, duplicate literal keys and fallback catalogs are respected. Namespace-imported
and fully qualified calls retain framework identity checks; local shadow functions defer.
Missing entries report `ichinya/laramago/laramago-missing-view` or
`ichinya/laramago/laramago-missing-translation` warnings.

Literal component names passed to the installed native `Inertia::render` facade or
`Inertia\ResponseFactory::render` are checked against a complete `inertia-pages`
catalog. Missing pages report `ichinya/laramago/laramago-missing-inertia-page`.
Direct factory subclasses, facade subclasses, custom package replacements, dynamic
component expressions, argument unpacking and incomplete catalogs defer.

Package namespaces, arbitrary dynamic keys/locales, implicit locale mutation, first-class
callables, argument unpacking, unsafe/dynamic catalogs, custom helpers and unsupported
catalog shapes defer. String phrase JSON references, other facades, Blade directives, `trans_choice`
and view factory methods are not covered. Catalogs are parsed without executing PHP.
