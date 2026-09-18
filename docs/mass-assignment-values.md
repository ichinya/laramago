# Mass-assignment value contracts

Native `forceFill()` calls with a directly written literal attribute array can
report `laramago-force-fill-write-contract` when a structured value is disjoint
from an explicit independent magic-property write contract. For example:

```php
/** @property-write \stdClass $payload */
class Record extends Model {}

$record->forceFill(['payload' => [1]]); // Declared write-contract mismatch.
$record->forceFill(['payload' => new \stdClass]);
```

The checker reuses Mago's effective magic-property write metadata, including
inherited contracts and separate `@property-read` / `@property-write` types. An
ordinary `@property`, a read-only declaration, or a real PHP property does not
establish this contract. Native and custom method declarations retain priority;
custom fill, force-fill, setter or guard dispatch disables the check. The
installed public, non-static `forceFill` signature (one non-reference, non-variadic
`$attributes` parameter), forwarding body and broad parameter contract are
verified before reporting.

Only concrete object/array types on both sides are checked, and an overlapping
union is deferred. Scalars, null, mixed, unresolved generics, nullable contracts,
dynamic or duplicate keys, spreads, references, JSON arrow paths, array variables
and unknown properties defer. This deliberately avoids treating a strict scalar
comparison as proof of a runtime error: Laravel invokes user setters from its
own non-strict PHP files, where scalar coercion can occur.

`fill()` and `create()` value diagnostics remain deferred. `fill()` filters its
input through `fillableFromArray()` and `isFillable()` before calling
`setAttribute()`; ignored values need never satisfy a setter contract. Guard
state and instance fillable lists can change at runtime. `forceFill()` bypasses
this filtering via `unguarded()`, but it does not itself establish a cast or
schema input type. Inferred schema, primitive-cast and setter types are therefore
not promoted to universal mass-assignment parameter contracts. Custom casts and
setter bodies may transform values, and a database builder write follows a
different path from model attribute assignment.

This is a declared-contract diagnostic, not a promise about database validation
or runtime exceptions. No schema completeness contract is needed, and missing
attribute names are a separate feature. Application bootstrap, setters, casts
and database operations are never executed.

SDK 1.48.1 provides post-analysis receiver and argument types through
`NodeAnalysisContext` and native type containment/overlap comparisons through
`TypeComparator`; these APIs are sufficient for this diagnostic. The remaining
scope is deferred for Laravel semantics, not because argument types are
unavailable. Expanding it requires a supported input contract or proof of the
actual assignment path, not reuse of a property's read type.

`php tests/force-fill-write-contracts.php` runs the real analyzer in enabled,
provider-disabled, changed-native-body, changed-native-contract and
changed-native-signature modes. It
checks independent write types, inheritance, custom dispatch, preserved native
errors and the conservative exclusions above.
