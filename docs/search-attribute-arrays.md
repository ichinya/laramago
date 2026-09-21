# Search attribute arrays

Eloquent's `firstOrNew()`, `firstOrCreate()` and `updateOrCreate()` methods give
different meanings to their two array-shaped inputs. The first `$attributes`
argument supplies equality predicates for a lookup. The second `$values`
argument supplies values only when a model is created or updated.

Laramago checks literal string keys in `$attributes` against the exact model's
complete [effective query source contract](query-source-contracts.md). For
example, this reports `missing` while leaving `write_only` alone:

```php
Article::query()->firstOrCreate(
    ['missing' => $value],
    ['write_only' => $otherValue],
);
```

The check requires both `complete: true` and
`native-column-semantics: true`. It accepts only one visible direct expression
rooted at a literal, zero-argument `Model::query()`, a native Eloquent builder
terminal, and a closed literal `$attributes` array whose keys are all explicit
string literals. Numeric string keys defer because Laravel may interpret them as
nested predicate tuples. Named and reordered arguments retain their native
roles.

The query-source contract is read-only evidence. It does not assert that a key
is fillable, guarded, writable, cast-compatible or accepted during insertion or
update. Laramago therefore does not validate `$values` here and does not apply a
field or `$fillable` catalog to either argument. The existing `forceFill()`
[key](mass-assignment-keys.md) and [value](mass-assignment-values.md) contracts
remain independent.

Saved or previously modified builders, local scopes, direct static model magic,
custom query factories or builders, PHPDoc/custom model dispatch, dynamic or
partly dynamic arrays, incomplete contracts and unasserted native semantics
defer. `findOrNew()` is outside this check because its arguments are a primary
key and projection columns, not search and creation attribute arrays.
`createOrFirst()` also defers because its creation attempt may succeed without
executing the fallback search. Native method signatures continue to report
invalid, missing and named arguments.

The analyzer reads Composer JSON and PHP syntax only. It does not construct a
model, execute a query, inspect a database or evaluate application code.
