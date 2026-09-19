# Inertia required prop contracts

An application may assert the required top-level props for selected Inertia pages in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "inertia-pages": {
          "complete": true,
          "paths": ["resources/js/Pages"],
          "extensions": ["vue"],
          "required-props": {"Admin/Users": ["title", "user"]},
          "shared-props": {"complete": true, "names": ["user", "flash"]},
          "render-props-complete": true
        }
      }
    }
  }
}
```

The required list is an explicit contract for initial full-page responses and every runtime resolver target for that page name. Exclude props intentionally absent until a deferred load and props needed only in partial reloads. `shared-props.complete: true` asserts that `names` includes every top-level key available from all active `Inertia::share()` calls and middleware for these responses. `render-props-complete: true` asserts that required props cannot be supplied later by `Response::with()` or another response mutation. These are application assertions, not facts inferred from PHP or Vue source. A wrong assertion can produce a false warning.

With all three assertions present, `laramago-missing-inertia-prop` identifies a required name absent from a closed literal `Inertia::render()` or concrete `ResponseFactory::render()` props array and the asserted shared names. The page must exist in the configured page catalog. An omitted props argument is treated as an empty array. Names and matches are case-sensitive.

Dynamic pages, props variables, computed or dotted keys, array unpacking, numeric provider entries, chained `render()->with()`, malformed or incomplete assertions, altered factory merging, and custom facade or factory dispatch remain unknown. Dotted names defer because Inertia expands them into nested top-level props. This diagnostic does not claim that Laravel throws at the call site or that a Vue component will fail at runtime. It does not execute JavaScript, bootstrap Laravel, inspect request headers, or model partial/deferred response resolution.
