# Effective query source contracts

Literal query columns can be checked against an explicit complete contract for
the effective source of a fresh standard Eloquent query:

```json
{
  "extra": {
    "laramago": {
      "query-sources": {
        "App\\Models\\Article": {
          "complete": true,
          "native-column-semantics": true,
          "columns": ["id", "articles.id", "title", "published_at"]
        }
      }
    }
  }
}
```

`complete: true` asserts that `columns` contains every literal SQL column
reference accepted by the effective source produced by
`App\Models\Article::query()`. This is stronger than a migration or model-field
catalog. It must account for the model table, qualification, global scopes,
aliases, connection-specific sources, and every callback that can modify a fresh
query before or during terminal execution. References are compared as exact,
case-sensitive strings. Contracts apply only to the exact model class and are not
inherited. Duplicate case-insensitive model keys and malformed entries disable
that model's contract.

`native-column-semantics: true` separately asserts native argument positions,
literal column forwarding and terminal execution for the supported model and
builder methods. Method ownership checks reject ordinary overrides; they do not
prove the body of a patched vendor method or runtime macro. If custom behavior
rewrites column names, omit this assertion. Both assertions are required; the
analyzer does not derive them from a schema. Catalogs refresh at each analysis
initialization and are loaded only when a relevant method is analyzed.

The analyzer reports `laramago-query-source-missing-column` only when the entire
query is one visible expression rooted at a literal `Model::query()`, ends in a
zero-argument `get()`, `first()`, `firstOrFail()` or `sole()`, and contains only
supported standard filters or ordering calls with literal column arguments. This
closed chain prevents a later join from turning an earlier missing reference into
a valid one. Both operands of the two-column `whereColumn` shortcut are checked.

Any `join`, `from`, `select`, `addSelect`, raw call, local scope, unknown method,
closure/array/dynamic column, variable-held builder, terminal projection argument,
custom builder, query factory or magic dispatcher makes the whole chain unknown.
Direct static magic calls such as `Article::where(...)` also defer because Mago
1.48.1 does not expose those forwarded calls to the targeted lifecycle hook used
by this check. Incomplete or absent contracts remain silent. Projection and
aggregate column validation are separate concerns.

The contract is advisory static-analysis input. Laramago reads Composer JSON and
source syntax only; it does not construct a model, apply a scope, boot Laravel or
connect to a database.
