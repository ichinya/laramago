# Mass-assignment key contracts

The analyzer checks literal string keys in native `Model::forceFill()` calls
against the existing [complete exact-model field catalog](model-field-catalogs.md).
It reports `laramago-force-fill-missing-field` when a key is absent from that
explicit contract. The warning describes a catalog mismatch, not a guaranteed
database failure or runtime exception.

```php
// With a complete Article catalog containing "title" and "payload":
$article->forceFill(['title' => 'Example']); // accepted
$article->forceFill(['titel' => 'Example']); // missing catalog field
```

The catalog must enumerate every valid attribute across table and connection
variants, mutators, casts and runtime definitions. Schema columns or `$fillable`
alone never establish completeness. Contracts are not inherited; malformed,
incomplete or duplicate normalized model entries defer. An explicitly complete
empty catalog can establish absence. Matching is case-sensitive.

Only direct instance calls with a single literal array argument are supported,
including the native named `attributes` argument. The analyzer verifies the
native method's signature, broad argument contract, forwarding body and relevant
mass-assignment dispatch. Custom `fill`, `forceFill`, `setAttribute`, unguarding,
attribute filtering or fillability methods defer. Incomplete hierarchies,
generic/intersection/union receivers and custom method documentation also defer.
The dispatch proof is shared with the independent
[force-fill value contract check](mass-assignment-values.md).

Explicit native properties and magic-property documentation take priority over
catalog absence. JSON-arrow paths, empty keys and numeric keys defer. The entire
array defers when it contains dynamic, unkeyed, unpacked, duplicate or referenced
items. Array variables, nullsafe calls and indirect callable invocations defer.
Values are not inspected by this check; callback values receive normal native
analysis. No missing-name diagnostic suppresses native argument, duplicate-key
or missing-method errors.

Ordinary `fill()` and `create()` remain outside this check. Native `fill()` first
filters attributes and then checks fillability; discarded keys may never reach a
setter, and application discard callbacks can handle them. Native builder
`create()` additionally constructs a model and saves it. A useful negative
diagnostic for those paths needs a proven effective assignment/constructor
contract, or a separate explicit input-key contract covering discarded inputs.
Static `$fillable` or schema snapshots do not provide that proof. Builder update
keys and JSON subpath contracts are separate concerns. Analysis never bootstraps
Laravel, executes application callbacks or accesses a database.
