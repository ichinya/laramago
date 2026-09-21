# Query-local aggregate result properties

Laravel's `withCount`, `withSum`, `withAvg`, `withMin`, `withMax` and
`withExists` methods add selected aliases to one mutable query builder. Those
aliases are properties of models hydrated by that query, not properties of every
instance of the model class.

The pinned Laravel `QueriesRelationships::withAggregate()` uses `selectSub()` for
ordinary aggregates and `selectRaw()->withCasts()` for `exists`. `withCount()`
forwards `'*'` and `count`; the other helpers forward their SQL aggregate name.
A later query-builder `select()` resets both selected columns and select
bindings. The builder methods return the same mutable object, so the reset can
happen through another variable referencing that builder. Accessors and model
casts still take precedence when Laravel reads a hydrated attribute.

Mago SDK 1.48.1 invokes return and assertion providers while the call is being
typed, but their `Invocation` contains only the declaring class, method name,
receiver type, file-local byte span and arguments. It does not contain the source
file, receiver expression or an object identity. The real-engine regression
`tests/query-aggregate-sdk-boundary.php` places a direct fresh query and a mutable
builder call at the same byte offsets in different files. Their complete exposed
provider records are identical. `BeforeAnalysisContext` has no source files, and
source-aware node and method-call hooks run only after the file has been analyzed,
when they cannot replace the already inferred terminal model type.

Encoding an aggregate alias in a builder generic would therefore leak stale
state in this valid program:

```php
$query = Post::query()->withCount('comments as selected_count');
$alias = $query;
$alias->select(['posts.*']);

return $query->firstOrFail()->selected_count;
```

The final model has no `selected_count` column because `select()` removed the
subquery. Refining only `$alias`, or only the return value of `select()`, cannot
invalidate the marker still carried by `$query`. A class-keyed or span-keyed map
would also cross-contaminate independent queries or equal offsets in other files.

A safe bounded implementation needs a synchronous return-type hook with the
current source-file identity and receiver syntax, plus a way to prove a closed
fresh terminal chain such as `Post::query()->withCount(...)->firstOrFail()` before
publishing the result intersection. General builder-variable support additionally
needs engine-managed object identity, alias-aware mutation invalidation, branch
joins and conservative invalidation on escapes, callbacks, scopes and unknown
builder methods. The result provider must still defer to native declarations,
PHPDoc, accessors and casts, and must preserve native unknown-property errors when
the proof is absent.
