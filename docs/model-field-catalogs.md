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
          "fields": ["id", "title", "slug", "summary"],
          "serialization": {
            "complete": true,
            "keys": ["author", "display_name"]
          }
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

The nested `serialization` contract enables `$hidden` validation without
pretending that every hideable name is a field. Its `keys` list supplements
`fields` with every relationship and appended name that may be filtered during
serialization. `serialization.complete: true` asserts that the supplemental
list is complete. Omit this nested contract when runtime relationships, appends,
or other non-field serialization keys cannot be enumerated.

Relationship keys use the name stored on the model before serialization. For
example, list `userProfile`, not the `user_profile` key that Laravel may emit
after the hidden filter runs. Appended names use their stored append key.

The analyzer reports `laramago-missing-model-field` only for unkeyed literal
string items in directly declared `$fillable` or `$guarded` arrays on the
cataloged model. The exact guarded array `['*']` retains Laravel's total-guard
meaning. In mixed guarded arrays, `*` does not suppress checks for the other
literal names; the unusual `*` entry itself defers.

With a complete nested serialization contract, the analyzer reports
`laramago-missing-model-serialization-key` for a literal name in the exact
model's directly declared `$hidden` array only when it is absent from both
`fields` and `serialization.keys`.

Dynamic expressions, keyed or unpacked arrays, inherited declarations, PHP
attributes, incomplete class hierarchies, nonstandard framework provenance, and
models overriding relevant mass-assignment dispatch defer. Guarded validation
requires native `fill()`, `getFillable()`, `getGuarded()`, `isFillable()`,
`isGuarded()` and `fillableFromArray()` provenance. Analysis does not call
`isGuardableColumn()` or connect to a database.

Hidden validation also defers for non-literal effective arrays, custom
`getHidden()` or `getVisible()` implementations, and custom Eloquent
serialization dispatch such as `toArray()`, arrayable attribute, append, or
relationship collection. The analyzer never resolves runtime relationships or
executes serialization.
