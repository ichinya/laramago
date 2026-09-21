# Untyped local scope bodies

When a local Eloquent scope has no return contract, Laramago can preserve `Builder<Model>` for a single `return $query;` or a `where` / `orWhere` chain rooted in the first query parameter:

```php
public function scopeForUser($query, int $userId)
{
    return $query->where('user_id', $userId);
}
```

Every native chained method must have an identity-preserving `$this` return contract. In addition to Eloquent `where` and `orWhere`, inference recognizes the Query Builder predicates supported by `EloquentQueryProvider` and `orderBy` / `orderByDesc`. Forwarded methods must be declared by the installed Query Builder and must not be shadowed by an Eloquent declaration, model method, local scope, or PHPDoc method. Checks use the actual receiving model, including for inherited scopes.

Safe arguments include scalar literals, `true`, `false`, `null`, other scope parameters, known enum case `name` properties, and backed enum case `value` properties. Array literals are checked recursively, including their keys. Unpacking, references, calls and assignments in arrays remain unsupported. The parser only reads syntax; it never executes models, scope bodies, autoload files, or application bootstrap code.

One branch layout is supported for enum conversion:

```php
public function scopeByStatus($query, Status|string $status)
{
    if ($status instanceof Status) {
        return $query->where('status', $status->value);
    }

    return $query->where('status', $status);
}
```

The condition must test a value parameter against a known enum. Both returns must preserve the query, and the enum property is allowed only inside the guarded branch. A scalar return in either branch, query mutation, additional statements, or an `else` / `elseif` layout remains unknown.

Assignments, other branches, closures, arbitrary calls, dynamic methods, unpacking, and references are outside this inference. Scalar and unknown returns stay unknown. This deliberately bounded rule does not infer general PHP return types or assume every scope returns a query. It refines scope call results; it does not supply a missing parameter declaration inside the scope body.

Explicit PHP/PHPDoc return types, including `mixed` and raw `Builder`, retain priority. Existing dispatch checks preserve native Builder methods, model PHPDoc methods and custom builders. Consequently, a later scope with an explicit raw `Builder` return can still erase the model generic; write an accurate generic contract to preserve it.

An explicitly configured macro catalog also takes precedence: a possible matching
Builder macro prevents forwarding inference, and unknown catalog registrations
disable this refinement. This does not discover arbitrary runtime macro registration.
