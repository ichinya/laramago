# Related projection source contracts

Eager-load colon projections can be checked against an explicit contract for
the effective query source of one exact owner relation:

```json
{
  "extra": {
    "laramago": {
      "relation-query-sources": {
        "App\\Models\\Article::comments": {
          "related-model": "App\\Models\\Comment",
          "complete": true,
          "native-column-semantics": true,
          "columns": ["id", "comments.id", "article_id", "body"]
        }
      }
    }
  }
}
```

`complete: true` asserts that `columns` contains every literal SQL column
reference accepted by the effective eager-load query source for that exact
owner and relation. The assertion covers the actual relation context, including
the connection inherited from the owner, relation constraints, global scopes,
table selection, aliases, and callbacks that affect terminal execution. It is
intentionally separate from a fresh related model query-source contract because
Laravel can propagate the owner's connection before it creates the relation
query.

`related-model` is checked against the model inferred from the relation method.
`native-column-semantics: true` asserts that the installed native eager-loading
path passes supported colon projection items to `select()` with their ordinary
meaning. Omit either assertion when the relation or installed framework changes
that behavior. Contract keys identify exact owner classes and relation methods;
they are case-insensitive and are not inherited. Duplicate normalized keys and
malformed contracts disable that entry. Column references remain exact and
case-sensitive.

The analyzer reports `laramago-related-projection-missing-column` for a single
visible expression shaped like this:

```php
Article::query()->with('comments:id,body')->get();
```

The supported terminals are zero-argument `get()`, `first()`, `firstOrFail()`,
and `sole()`. The relation method must be parameterless and statically proven to
return one direct native `hasOne`, `hasMany`, `belongsTo`, `morphOne`, or
`morphMany` factory call. These relation kinds create the query from the related
model and add predicates; the explicit relation-source contract accounts for
the resulting context.

Saved builders, additional chain steps, repeated `with()` calls, callbacks,
arrays, nested relation paths, dynamic strings, terminal projection arguments,
PHPDoc-overridden relation targets, modified relation queries, custom query
factories or dispatchers, pivot/through relations, aliases, wildcards, and SQL
expressions remain unknown. Static `Model::with()` forwarding also remains with
native Mago. Missing, incomplete, mismatched, or unasserted contracts stay
silent.

Laramago reads Composer JSON and PHP syntax only. It does not instantiate a
model, execute a relation method, boot Laravel, inspect an environment file, or
connect to a database.
