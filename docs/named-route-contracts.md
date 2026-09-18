# Complete named-route contracts

By default Laramago makes no claim about missing named routes. Routes can be
registered by application code, packages, providers and conditional boot logic.
An application can explicitly assert the complete registry in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "named-routes": {
        "complete": true,
        "missing-route-resolver": false,
        "names": ["home", "account.show", "package.callback"]
      }
    }
  }
}
```

`complete: true` asserts that the list includes every route name available to all
analyzed calls, including provider and package routes. `missing-route-resolver:
false` asserts that no missing named-route resolver supplies URLs. Both are
required. Missing completeness assertions and missing or malformed catalogs disable
this diagnostic. Laramago cannot detect an incomplete list marked complete; stale
or omitted names in such a list can cause false warnings.
Names are case-sensitive; an explicitly empty list is allowed.

For exact native `Illuminate\Routing\UrlGenerator::route()` and
`Illuminate\Routing\Redirector::route()` receivers, absent literal string names
produce `laramago-missing-named-route`. Positional arguments and their native
`name:` / `route:` named arguments are supported. Native declaration locations
must belong to Laravel's framework tree. No return types or native errors change.

The installed native `route()` and `to_route()` helpers receive the same check for
global calls, namespace fallback and imported function aliases. Helper diagnostics
also require Laravel's standard helper forwarding bodies, native `app()` and
`redirect()` dispatchers, and native URL generator/redirector route methods.
Explicit binding catalogs for `url`, `redirect` or their standard concrete and
contract aliases disable affected helper checks. The diagnostic points to the
literal route name and does not replace helper signatures, PHPDoc or native errors.

Native `URL::route()` and `Redirect::route()` facade calls receive the same
literal-name warning, including imported class aliases and named arguments. Checks
require standard facade accessors and root dispatch; concrete facade methods,
custom roots and relevant URL/redirect container bindings defer to native analysis.
Redirect checks also require the native URL generator. PHPDoc signatures and
native argument/return diagnostics remain unchanged.

Dynamic values, concatenations, argument unpacking, subclasses, union receivers,
custom facades, signed-route entry points, middleware aliases/groups, route parameters and runtime registry
reconstruction are outside this subset. Keep the contract current when route
providers change; omit it when a complete registry cannot be asserted. The
extension reads JSON and PHP syntax only and does not bootstrap Laravel, execute
route/provider files or query a database.
