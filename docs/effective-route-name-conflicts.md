# Effective route name conflicts

`effective-route-manifest` enables `laramago-effective-route-name-conflict` only
for an independently asserted final collection. This is a warning about duplicate
names on distinct surviving routes, not a claim that HTTP dispatch always fails.
Laravel's name lookup can select one route while another route with that name
remains reachable by its own method/domain/URI.

Configure `extra.laramago.effective-route-manifest` in `composer.json`:

```json
{
  "native-collection": true,
  "final-surviving-routes": true,
  "selection-complete": true,
  "cache-selection": "uncached",
  "routes": [
    {
      "name": "account.show",
      "methods": ["GET", "HEAD"],
      "domain": "",
      "uri": "accounts/{account}",
      "file": "routes/web.php",
      "start": 100,
      "contentHash": "0000000000000000000000000000000000000000000000000000000000000000"
    }
  ]
}
```

The example offset and SHA-256 are placeholders. Supply the actual byte offset
of the route-name literal and SHA-256 of the whole source file; the route metadata
export exposes these fields. The analyzer validates each name/location/hash
against parsed source and validates both diagnostic locations against the files
actually analyzed by Mago. It never executes the route source.

The contract asserts all of the following:

- Each row represents a distinct final route object after registration,
  replacements, group prefixes and route mutations, not a registration attempt.
- The selected effective collection uses native collection identity semantics.
- Every surviving named route is represented. Conditional providers and source
  selection have already been resolved by the contract author.
- `cache-selection` explicitly identifies `cached` or `uncached`; the manifest
  describes that selection. The analyzer neither reads nor rebuilds route caches.
- Methods, domain and URI describe effective canonical values. URI values normally
  omit their leading slash; `/` represents the root. Domain is empty if absent.

The route declarations themselves need not have those final method/domain/URI
values if application registration changes them; those values are assertions,
not inferred runtime facts. Source hashes validate the anchors, not the truth of
those assertions. Do not enable this contract if custom collection or unresolved
registration behavior makes the assertions unavailable. Custom binding, controller
dispatch and dependency injection are not inferred or validated by this check.

Any invalid row, stale hash, unknown field, missing selection assertion, repeated
source anchor or overlapping method/domain-plus-URI dispatch key disables the
whole manifest. Overlapping dispatch keys are deliberately unsupported: interpreting
replacement order or partially surviving method sets would need more information.
The limit is 256 routes and the underlying bounded source export must be complete
and free of read/parse errors. Names match exactly and case-sensitively.

A single surviving replacement does not warn. Distinct surviving HTTP methods
or domains can conflict if their names match. Both source locations must be part
of the current Mago analysis; a single-file analysis cannot report a cross-file
conflict whose other location is not indexed. Existing source-only duplicate notes
remain separate and retain their advisory semantics.

This step does not add controller argument/DI compatibility or infer an effective
route manifest automatically. Without the contract there is no new diagnostic.
