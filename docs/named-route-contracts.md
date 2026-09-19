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

To avoid repeating literal route names in JSON, the same assertion can include
`"files": ["routes/web.php"]` alongside `names`. These are explicit project-relative
PHP files; absolute paths, parent traversal and symlinks outside the project are
rejected. The effective catalog is the union of `names` and extracted names.
`names` remains required and can be empty. Files never imply completeness.

With `files`, the application additionally asserts that those files register
active routes through Laravel's native Route facade/router, with no custom facade
root, relevant macro overrides, external name group or later removal/renaming.
The union must describe names available to every analyzed call. Laramago does not
prove activation, registration order, URI overwrites or environment selection.
Keep package/provider names in `names`, and use a manual-only catalog when these
assertions or the supported source subset do not fit the application.

Supported statements are native `Route::get/post/put/patch/delete/options/any`
calls with a literal URI and an action followed by literal `->name(...)` calls.
Concrete route names append when repeated. Actions can be literal controller
strings, `Controller::class`, literal two-element controller-method arrays, or
closures; handler bodies are not searched for registrations. Imports, class aliases
and namespaces are resolved from syntax. Nested `Route::name('admin.')->group(function
() { ... })` and `Route::group(['as' => 'admin.'], function () { ... })` concatenate
group prefixes with the route name. Group closures must have no parameters or captures.
Only positional arguments are accepted. No route file or callback is executed.

This is deliberately a strict input language: unreadable or malformed files,
conditions, includes, resources, unnamed routes, dynamic expressions, extra group
attributes and unsupported fluent methods disable the whole catalog's missing-name
checks. An incomplete extraction never enables negative diagnostics, even when
some names were extracted successfully. Duplicate names, resource expansion,
route parameters and source-location catalogs are separate concerns.

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

Native `Response::redirectToRoute()` and exact concrete
`ResponseFactory::redirectToRoute()` calls also check literal `route:` names.
The zero-argument `response()->redirectToRoute()` form is covered when the
installed helper returns the native response factory contract through `app()`.
Checks require the native factory, redirector and URL generator declarations,
the factory-to-redirector and redirector-to-generator forwarding bodies, standard
constructor wiring, and the facade accessor. Explicit response-factory,
redirector or URL-generator bindings disable this chain. Calls on arbitrary
response-factory contract variables, custom helpers, subclasses and
`response($content)` defer to native analysis. No response creation or route
provider is executed.

The same catalog checks literal names on native `signedRoute()` and
`temporarySignedRoute()` calls through exact URL generator/redirector receivers
and the native URL/Redirect facades. These checks require the installed native
URL generator forwarding chain, public concrete declarations, parameter names,
required/default argument shape and literal native defaults. Reference or variadic
declarations defer to native analysis. Signed calls accept
`parameters` before `expiration`; temporary signed calls require `expiration`
before `parameters`. Reordered native named arguments are supported, including
`name:` on URL calls and `route:` on redirects. Diagnostics point to the literal
name; expiration types, PHPDoc return types and argument errors remain native.
Configured URL bindings disable signed redirect checks because their internal
generator is no longer proven. No signing key, expiration value or signature is
evaluated, and signature validation methods do not reference route names.

Dynamic values, concatenations, argument unpacking, subclasses, union receivers,
custom facades, middleware aliases/groups, route parameters and runtime registry
reconstruction are outside this subset. Keep the contract current when route
providers change; omit it when a complete registry cannot be asserted. The
extension reads JSON and PHP syntax only and does not bootstrap Laravel, execute
route/provider files or query a database.
