# Raw original Eloquent attributes

Mago 1.48.1 already resolves the installed Laravel conditional return contract for
`getRawOriginal()` and `getRawOriginal(null)`: these calls return the whole original
attributes array. Named `key: null`, omitted keys with a named default, and ordinary
unsaved models need no Laramago provider. The default does not replace the whole
array when the key is null. Array elements remain `mixed`.

Laramago intentionally does not infer `getRawOriginal('field')` from migration
columns or attribute casts. Installed Laravel `HasAttributes::getRawOriginal()`
returns `Arr::get($this->original, $key, $default)` without applying read casts.
`setRawAttributes($attributes, true)` accepts an arbitrary array, assigns it to
`attributes`, and synchronizes `original`; `syncOriginal()` and
`syncOriginalAttributes()` can also update that state. A model-typed variable does
not prove that its original values came from a database row.

For a genuinely database-loaded row, JSON and date storage values differ from
read-cast arrays and date objects. `SchemaIndex` and `AttributeTypes::column()`
describe known columns, while `ModelReflection` handles table metadata and casts.
None proves this receiver's original state or which columns were loaded. A
non-nullable column also does not prove presence in an unsaved or partially loaded
model. A keyed lookup can therefore return null or the explicit default; even
`string|null` would wrongly exclude an object supplied through `setRawAttributes`.
Unknown keys, dynamic keys, arbitrary defaults, and raw writes retain native
diagnostics. No schema-derived whole-row shape is fabricated.

A future keyed refinement requires trustworthy receiver-state provenance, or an
explicit user-supplied contract. The current return-type provider context supplies
receiver types and argument expressions but no general model-state or alias
tracking. A source-wide absence of obvious raw writes is not sufficient proof.
Custom method overrides and PHPDoc remain under Mago's native handling.

`php tests/raw-original.php` runs real-Mago regressions with and without the worker,
including nullable/dynamic keys, named defaults, unsaved models, JSON/date casts,
known migration columns, custom contracts, invalid calls, raw mutation, and mixed
array elements. Modified framework docs and bodies demonstrate that native
contracts, rather than a hard-coded provider, govern the result. Fixtures and
reports are created only in unique system temporary directories.
