# Explicit environment name catalogs

Environment names may be supplied through an explicit Composer catalog:

```json
{
  "extra": {
    "laramago": {
      "environment-names": {
        "names": ["APP_ENV", "APP_KEY", "VITE_API_URL"],
        "complete": false
      }
    }
  }
}
```

Names are case-sensitive strings. Duplicate names are collapsed in their first-seen order.
An incomplete catalog retains known names, but an uncataloged name stays unknown.
Set `complete` to `true` only when the list describes every environment name the
application permits or expects across its deployments, including names provided by
deployment configuration. An `.env.example` or any other template is not evidence
of this completeness. An explicit empty list may be complete. Even a complete
catalog does not prove that any variable exists at runtime, that a value has a
particular type, or that an `env()` call with a fallback is an error.

The catalog reads Composer metadata only. It never reads `.env` files, evaluates
`env()` or `getenv()`, or obtains environment values. It does not choose an
authentication model or affect configuration value inference. Helper references,
template interpolation and duplicate template declarations, and Vite references
are separate features. This catalog alone emits no diagnostics.

Malformed catalogs are ignored. `names` must be a list of nonempty strings without
ASCII whitespace, ASCII control characters or `=`. `complete` is optional and defaults to
`false`; when present it must be a boolean.
