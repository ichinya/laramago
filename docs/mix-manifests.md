# Static Mix manifest catalog

The Mix catalog reads JSON files from explicit project-relative paths. It does
not call Laravel, evaluate application configuration, or infer the value of
`public_path()`. Add each manifest directory that the analysis should inspect
under `extra.laramago.reference-catalogs.mix-manifests.files` in the consuming
project's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "mix-manifests": {
          "files": [
            {"directory": "", "path": "web assets/mix-manifest.json"},
            {"directory": "dist", "path": "web assets/dist/mix-manifest.json"}
          ]
        }
      }
    }
  }
}
```

`directory` is the literal second argument to `mix()` without an optional
leading slash. The empty string selects the default. `path` points to the
corresponding manifest inside the project; it must end in `mix-manifest.json`.
Each path must be explicitly asserted, including a custom public root. Paths
can contain spaces but cannot traverse upward or use absolute paths.

`MixManifestCatalog::entries()` returns the decoded string map with exact keys
and values. Like Laravel's `Mix`, `contains()` adds a leading slash to a
requested path when needed. JSON duplicate keys use the final value. No
slashes or case in manifest entries are rewritten. `reset()` reloads both
Composer configuration and file snapshots.

`status()` is `complete` only for a parsed JSON object whose values are all
strings. A complete status means the catalog has the literal map, **not** that
Laravel will use this file at runtime or that a missing key will throw. Missing
files report `missing`; inaccessible files or symlinked paths report
`unreadable`; bad JSON reports `invalid-json`; a non-object or a map with
non-string values reports `invalid-shape`. Invalid or absent contracts report
`invalid-configuration` or `unconfigured`. The latter statuses return `null`
from `entries()` and `contains()` for unknown names. `invalid-shape` is a
conservative unsupported-schema status, not a claim that Laravel necessarily
throws: native Mix may coerce some values or return the unversioned path.

`hotFileState()` separately reports `present`, `absent`, or `unknown` for the
hot file beside a configured manifest. This is a filesystem observation at
the same snapshot, never a runtime guarantee. The catalog never reads or
executes hot-file contents. Consumers must independently establish the native
Mix implementation, effective public root, hot-mode behavior, and relevant
configuration before treating a missing manifest key as a runtime diagnostic.
