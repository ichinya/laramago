# Model field catalogs

Literal `$fillable` and `$guarded` names can be checked against an explicit
complete field catalog for an exact model class:

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
normalized keys disable that model's contract. Fillable names use the catalog's
exact case. Guarded names follow Laravel's case-insensitive matching. Malformed
contracts defer without diagnostics.

The analyzer reports `laramago-missing-model-field` only for unkeyed literal
string items in directly declared `$fillable` or `$guarded` arrays on the
cataloged model. The exact guarded array `['*']` retains Laravel's total-guard
meaning. In mixed guarded arrays, `*` does not suppress checks for the other
literal names; the unusual `*` entry itself defers.

Dynamic expressions, keyed or unpacked arrays, inherited declarations, PHP
attributes, incomplete class hierarchies, nonstandard framework provenance, and
models overriding relevant mass-assignment dispatch defer. Guarded validation
requires native `fill()`, `getFillable()`, `getGuarded()`, `isFillable()`,
`isGuarded()` and `fillableFromArray()` provenance. Analysis does not call
`isGuardableColumn()` or connect to a database.
