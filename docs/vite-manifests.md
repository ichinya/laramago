# Static Vite manifest catalog

The Vite catalog reads only explicitly configured project-relative JSON files.
Configure each literal build directory and manifest path in the consuming
project's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "vite-manifests": {
          "files": [
            {
              "build-directory": "dist",
              "path": "web assets/dist/entries.json",
              "hot-file": "web assets/hot"
            }
          ]
        }
      }
    }
  }
}
```

`build-directory` is the literal directory passed to Laravel's Vite methods;
`path` is the manifest's location inside the project. Both are required.
`hot-file` is optional and independent of the build directory. Laravel can
change all three locations through `useBuildDirectory()`,
`useManifestFilename()`, and `useHotFile()`, and can use a different public
root. This catalog never infers those settings. The paths must be relative,
cannot traverse upward, and cannot pass through symlinks.

`ViteManifestCatalog::entries('dist')` returns the decoded manifest keyed by
exact source names. Its chunk arrays retain `file`, `src`, `imports`,
`dynamicImports`, `css`, `assets`, integrity data, and any other fields.
`entry($source, 'dist')` selects one chunk. `contains($source, 'dist')` tests
the exact key without adding a slash. Duplicate JSON keys follow PHP's
last-value-wins decoding. `reset()` reloads the configuration and snapshots.

`status()` reports `complete` only for a JSON object whose chunks are objects
with string `file` fields and whose present import, CSS, and asset lists
contain strings. It reports `missing`, `unreadable`, `invalid-json`,
`invalid-shape`, `oversized`, `invalid-configuration`, or `unconfigured` as
applicable. Files above 8 MiB, maps above 8192 entries, and configurations
above 32 files, and JSON nested beyond 64 levels are bounded. In incomplete states, `entries()` and
`contains()` return `null` rather than claiming that a key is absent.
`hotFileState()` reports `present`, `absent`, or `unknown` for a configured
hot-file path at snapshot time; it never reads the file contents.

This is file metadata, not proof of which manifest Laravel serves. Laravel's
`__invoke()` and `asset()` bypass the manifest while hot mode is active,
whereas `content()` still reads it. Laravel also permits custom public paths,
asset resolvers, manifest names, and Vite instances. Therefore this catalog
does not issue missing-entry diagnostics or infer runtime asset URLs.
