# Permitted middleware reference policy

Applications can opt into an exact permitted-reference policy for literal native
route middleware calls:

```json
{
  "extra": {
    "laramago": {
      "middleware-references": {
        "complete": true,
        "references": ["web", "auth:admin", "App\\Http\\Middleware\\Authenticate"]
      }
    }
  }
}
```

This is an advisory source policy. `complete: true` says every literal reference
that the project permits at supported call sites appears in `references`.
`laramago-uncataloged-middleware-reference` reports a literal outside that list.
It does not assert that listed references resolve, that unlisted references fail,
or that Laravel will execute the middleware. Runtime aliases, groups, callable
strings, container bindings, instances, factories and custom resolvers remain
independent.

References use exact case-sensitive string identity. No colon parsing occurs:
`auth`, `auth:admin` and `Handler::run` are three distinct policy entries. This is
intentional because Laravel checks exact group and closure-alias keys before its
context-dependent parsing, while Pipeline dispatch accepts callable strings.
Middleware parameter parsing and signature checking require a separate proven
resolution contract.

The initial consumer checks direct calls to the installed native
`Illuminate\Routing\Route::middleware()` method. It supports one literal string,
a flat unkeyed literal string list, and the single named `middleware` argument.
The call must resolve to the exact concrete Route class and the audited native
method declaration and body. A getter call has
no references. Dynamic values, unpacking, keyed or mixed lists, first-class
callables, subclasses, custom routers, facade/group registration and changed
native bodies defer without a policy warning. Class-constant expressions are also
outside this initial literal-string subset and retain native Mago diagnostics.
Calls with extra positional arguments also defer and retain Mago's native
argument-count diagnostic.

The policy is separate from `middleware-aliases` and `middleware-groups`.
Completeness of either runtime metadata catalog never enables this warning, and
their entries are not copied into the policy. A missing, incomplete or malformed
policy produces no warnings. Duplicate policy entries are harmless; empty strings,
colons, backslashes and whitespace retain exact identity.

The analyzer reads only `composer.json` and source syntax. It does not bootstrap
Laravel, load application classes, execute selected PHP, or inspect runtime
container state.
