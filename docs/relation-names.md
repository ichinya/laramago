# Relation name completeness contracts
Missing relation-name warnings are opt-in per exact model class. Configure Composer:

```json
{
  "extra": {
    "laramago": {
      "relation-names": {
        "App\\Models\\Article": {
          "complete": true,
          "dynamic": ["featuredAuthor"]
        }
      }
    }
  }
}
```

`complete: true` asserts that this exact model's available relations consist of its declared methods plus every runtime relation name listed in `dynamic`. Include inherited/runtime `resolveRelationUsing` registrations yourself; Laramago does not execute or discover them. Omit the contract for open-ended relation registration. Completeness is not inherited by subclasses. Class keys are case-insensitive; repeated normalized keys disable that class contract. Malformed contracts defer. Dynamic relation names are case-sensitive and must be simple identifiers; invalid lists disable that model's contract.

The analyzer reports `laramago-missing-relation` for missing literal names in native `with`, instance `load` and `loadMissing`, and `has`, `orHas`, `doesntHave`, `orDoesntHave`, `whereHas`, `orWhereHas`, `whereDoesntHave`, `orWhereDoesntHave` and `withWhereHas` calls on standard Eloquent models and builders. Static imported class aliases are supported. Lists, callback-map keys, named arguments, column suffixes and dotted paths through declared generic related-model types are supported. Each missing nested segment needs its own exact model's completeness contract. Listed dynamic relations stop traversal because their target types are unknown; registered names take precedence over declared methods.

Without a completeness assertion, absent names retain existing behavior and produce no new missing-name warning. Existing known non-relation method diagnostics remain. Custom relation resolvers, magic instance dispatch, unknown return types, incomplete hierarchies, dynamic names and unpacked arguments defer. Static calls with custom query/builder factories or custom static magic dispatch defer. Late `self`/`static` and variable class expressions are not resolved. This is a bounded literal-path diagnostic, not runtime registry validation or exhaustive eager-load syntax support.
