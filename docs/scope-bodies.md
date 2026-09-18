# Untyped local scope bodies

When a local Eloquent scope has no return contract, Laramago can preserve `Builder<Model>` for a single `return $query;` or a `where` / `orWhere` chain rooted in the first query parameter:

```php
public function scopeForUser($query, int $userId)
{
    return $query->where('user_id', $userId);
}
```

Every chained method must be declared by the installed Eloquent Builder with an identity-preserving `$this` return contract. Arguments must be scalar literals, `true`, `false`, `null`, or other scope parameters. The parser only reads syntax; it never executes models, scope bodies, autoload files, or application bootstrap code.

Assignments, branches, multiple statements, closures, array expressions, arbitrary calls, dynamic methods, unpacking, and references are outside this inference. Scalar and unknown returns stay unknown. This deliberately bounded rule does not infer general PHP return types or assume every scope returns a query.

Explicit PHP/PHPDoc return types, including `mixed` and raw `Builder`, retain priority. Existing dispatch checks preserve native Builder methods, model PHPDoc methods and custom builders. Consequently, a later scope with an explicit raw `Builder` return can still erase the model generic; write an accurate generic contract to preserve it.
