# Selected authentication guards and state

`auth('admin')` and `Auth::guard('admin')` can resolve a standard guard class,
but that does not establish the model returned by every subsequent `user()` call.
Multiple configured guards can share that class and use different providers.
Standard guards also allow their user to change through `setUser()`, including
through another variable referencing the same guard. The authentication manager
caches guards, so even a fresh-looking helper chain can retrieve an existing object.

The Mago SDK 1.48.1 can transport additional `NamedObjectType` parameters even when
the underlying class declares no template. The real-engine test
`tests/auth-selected-state.php` verifies transport through direct calls, variables
and unions of the same guard class. Such a parameter is therefore a possible
transport mechanism, but it is not evidence of immutable object state.

The same test demonstrates the failure: after assigning the guard to an alias and
calling `setUser(new SelectedMember)` on that alias, an experimental return provider
still sees the initial `SelectedAdmin` parameter. It incorrectly accepts returning
that result as `SelectedAdmin|null`. Direct mutation has the same problem. Native
analysis retains its broader user contract and reports the narrower return as
unsupported. This experiment runs only in a separate test worker; it is deliberately
not registered in the production Laravel plugin.

`tests/auth-selected-guards.php` exercises the production plugin and a disabled
comparison with two same-class guards. It preserves native selected-user contracts,
checks that models do not leak across variables or branches, and retains unknown
method and property diagnostics. The direct `Request::user('admin')` entry point
continues to use the existing supported configuration contract.

No class-keyed cache or visible synthetic property is a valid substitute for
instance state. Safely extending selected-user inference needs either engine
support for mutation and alias invalidation, or a separately specified, explicit
application contract promising that the configured model remains valid after all
user/provider mutations. Existing Composer model metadata describes configuration;
it is not silently reinterpreted as that stronger immutability guarantee. Custom
guards can already declare their narrower user contract natively or in PHPDoc.
