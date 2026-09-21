# Route name duplicate candidates

`RouteNameDuplicateCandidates::export($root, $files)` reports repeated literal
route-name declarations from an explicit ordered list of project-relative PHP
files. It reuses the bounded `RouteMetadataExport` syntax subset and never loads
the application's autoloader, executes route files, or boots Laravel.

The result is an advisory source review aid. Its scope uses
`semantics: advisory-duplicate-name-candidates`, `evidence: source-only`, and
`exhaustive: false`. Each candidate has `confidence: source-only-candidate` and
`activeRouteConflict: unknown`. It preserves the repeated declaration's literal
span and source hash plus `firstLocation` for the earliest matching declaration.
Composed literal group names retain the raw leaf and ordered prefix-token
provenance at both locations.

Matching is exact-case. The explicit file list controls cross-file order, and
declarations within each selected file retain source order. Parse/read errors and
all truncation state from the route export remain in the result. Unsupported or
dynamic route syntax is absent rather than treated as negative evidence.

Repeated source names do not prove conflicting active routes. Laravel's native
collection can replace an earlier route with the same ordered methods, effective
domain, and URI; fluent names are applied after insertion. External groups can
change those identities or effective names, and conditions, providers, macros,
later mutations, and cached routes can change activation and ordering. Ordinary
duplicate retained names matter during route serialization, while other runtime
lookups and special generated names have different behavior. This exporter does
not emulate those runtime stages or label a candidate as an error.

For example, these declarations intentionally remain an advisory candidate even
though the later registration can replace the earlier native collection entry:

```php
Route::get('/same', FirstController::class)->name('shared');
Route::get('/same', SecondController::class)->name('shared');
```

An active-conflict diagnostic needs a separate explicit policy proving ordered
method/domain/URI identities, final effective names, retained-route replacement,
and the absence of unknown activation or later mutation. This source export does
not supply that proof.

## Optional analyzer notes

Mago can show each source-only candidate at both selected declarations. Enable
the policy with an explicit file list in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "route-name-duplicate-candidates": {
        "diagnose": true,
        "files": ["routes/web.php", "routes/api.php"]
      }
    }
  }
}
```

The analyzer emits the `laramago-route-name-duplicate-candidate` note at the
repeated literal and adds the first literal as a secondary location. Both files
must be part of Mago's analyzed source set, and both exact analyzed snapshots
must match the metadata hashes. Missing, unindexed, or changed snapshots defer
the candidate instead of attaching a location to different source text.

This diagnostic consumes the same bounded exporter described above. It does not
make the selected list complete and does not assert activation, effective route
identity, replacement order, or a runtime conflict. The named-route catalog's
separate completeness and runtime assertions remain necessary for missing-name
diagnostics and do not turn this note into active-route proof.
