# Model field catalogs

Literal `$fillable` names can be checked against an explicit complete field
catalog for an exact model class:

```json
{
  "extra": {
    "laramago": {
      "model-fields": {
        "App\\Models\\Article": {
          "complete": true,
          "fields": ["id", "title", "slug", "summary"]
        }
      }
    }
  }
}
```

`complete: true` asserts that `fields` contains every valid attribute name for
that exact model. The list must include names supplied by every database
connection and table variant, casts, accessors, mutators, custom attribute
dispatch, and runtime definitions. A migration schema alone does not establish
this contract. Omit the contract whenever those sources cannot be enumerated.

Contracts are not inherited. Class keys are case-insensitive; duplicate
normalized keys disable that model's contract. Field names are case-sensitive.
Malformed contracts defer without diagnostics.

The analyzer reports `laramago-missing-model-field` only for unkeyed literal
string items in a directly declared `$fillable` array on the cataloged model.
Dynamic expressions, keyed or unpacked arrays, inherited declarations, PHP
attributes, incomplete class hierarchies, nonstandard framework provenance, and
models overriding `fill()`, `getFillable()`, `isFillable()` or
`fillableFromArray()` defer. The catalog is a reusable proof boundary for
field-list diagnostics; currently only `$fillable` uses it.
