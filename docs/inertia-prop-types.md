# Inertia literal prop type contracts

Applications can opt in to a small frontend JSON type check for native Inertia
render calls. Add explicit per-page expectations to the `inertia-pages` catalog:

```json
"inertia-pages": {
  "complete": true,
  "paths": ["resources/js/Pages"],
  "extensions": ["vue"],
  "prop-values-unchanged": true,
  "prop-types": {
    "Admin/Users": {
      "title": "string",
      "count": "number",
      "active": "boolean",
      "tags": "array",
      "description": "string|null"
    }
  }
}
```

`prop-types` is an assertion supplied by the application. It is not inferred
from Vue prop names, PHP class names, or a TypeScript compiler. The optional
`prop-values-unchanged: true` asserts that no application code changes
these direct render prop values after the call. Invalid type entries are ignored.
The only accepted JSON type words are `string`, `number`, `boolean`, `array`,
and `null`; distinct words may be joined with `|`.

A warning is emitted only for a known, unambiguous page on the installed
native Inertia 3.x `Inertia::render()` facade or exact `ResponseFactory::render()`
receiver, with a direct closed PHP array of string prop keys. The factory's
render method must match the audited native body. The check compares literal
PHP strings, finite integers/floats, booleans, null, and unkeyed list arrays
containing only these values with the explicit JSON type. PHP numeric strings remain JSON strings;
there is no numeric coercion. An incompatible value produces the warning
`ichinya/laramago/laramago-incompatible-inertia-prop`.

Unknown values defer the entire call, including variables, closures, objects,
models, enums, dates, `Arrayable` or serializer results, unpacks, computed or
dotted keys, associative arrays, and arrays containing any dynamic value.
Shared and deferred props are not type checked.
Chained calls such as `->with()` also defer. This check does not prove that a
frontend prop exists or validate nested array shapes. Its warning is a static
contract discrepancy, not a runtime error claim.
