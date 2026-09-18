# Explicit model classes in Morph callbacks

Laravel applies `Relation::getMorphedModel()` even to fully qualified model class
strings passed to `whereHasMorph()`. A morph map can therefore redirect an
explicit `Post::class` to a different model. The native `$types` contract is
`string|array<int, string>`; it does not promise class-string identity.

Laramago leaves these callbacks native unless the application explicitly asserts
the class strings that will retain their identity:

```json
{
    "extra": {
        "laramago": {
            "morph-class-identity": ["App\\Models\\Post", "App\\Models\\Video"]
        }
    }
}
```

Each entry asserts that its exact class-string spelling is never remapped to a
different class by the effective morph map, including runtime registrations.
Names have no leading backslash. Matching is case sensitive, as morph-map keys
are case sensitive. This is an application contract, not inferred map discovery.
A missing or malformed list leaves callbacks native. An unlisted class in a
multi-model call leaves that entire callback native.

With this contract, a fully qualified literal class operand or flat list can
refine the callback:

```php
Comment::whereHasMorph('commentable', [\App\Models\Post::class, \App\Models\Video::class],
    function ($query, $type) {
        // $query: Builder<Post>|Builder<Video>
        // $type: class-string<Post>|class-string<Video>
    },
);
```

The callback must accept every alternative. Checking `$type` does not establish
correlated narrowing of `$query`. The same support applies to `orWhereHasMorph`,
`whereDoesntHaveMorph` and `orWhereDoesntHaveMorph`.

Inference also requires a direct literal public parameterless `MorphTo` relation,
ordinary model dispatch, concrete models without unbound templates, the installed
Laravel `QueriesRelationships` method/helper declarations, and the native callback
signature shape. A same-shaped application implementation, a Builder override, or
a custom helper keeps native analysis. Return forwarding checks that provenance
independently of the identity assertion.

Wildcards require runtime database discovery and stay native. String aliases,
relative/imported class operands, `self`/`static`, dynamic or spread arrays,
empty/keyed lists, custom builders and native relation-object arguments also stay
native. Existing native generics and explicit model/PHPDoc dispatch contracts take
priority. The provider never bootstraps Laravel, runs model methods or queries a
database.
