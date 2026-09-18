# Complete reference catalogs

Missing literal reference diagnostics are disabled by default. Applications can explicitly
assert that the following catalogs completely describe their runtime lookup behavior:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "views": {"complete": true, "paths": ["resources/views", "custom-views"]},
        "translations": {"complete": true, "path": "lang", "locales": {"en": ["en", "fr"]}}
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

The `view` helper checks conventional dotted or slash-separated literal names against
Blade/PHP/CSS/HTML files. The `trans` and `__` helpers check literal dotted PHP keys only
with an explicit literal locale listed in the contract. Static JSON overrides, nested
PHP arrays, duplicate literal keys and fallback catalogs are respected. Namespace-imported
and fully qualified calls retain framework identity checks; local shadow functions defer.
Missing entries report `ichinya/laramago/laramago-missing-view` or
`ichinya/laramago/laramago-missing-translation` warnings.

Package namespaces, arbitrary dynamic keys/locales, implicit locale mutation, first-class
callables, argument unpacking, unsafe/dynamic catalogs, custom helpers and unsupported
catalog shapes defer. String phrase JSON references, facades, Blade directives, `trans_choice`
and view factory methods are not covered. Catalogs are parsed without executing PHP.
