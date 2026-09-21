# Middleware handle parameter checks

`laramago-missing-middleware-parameters` checks literal native `Route::middleware()` registrations against indexed `handle()` declarations. It reports missing required middleware arguments, including references expanded through nested groups. No Laravel application code is executed.

This is opt-in because source declarations alone cannot establish the active router map, container bindings, callable behavior, exclusions, or pipeline implementation. Configure an explicit effective dispatch contract:

```json
{
  "extra": {
    "laramago": {
      "middleware-native-dispatch": true,
      "middleware-aliases": {"files": ["analysis/middleware-aliases.php"], "complete": true},
      "middleware-groups": {"files": ["analysis/middleware-groups.php"], "complete": true}
    }
  }
}
```

The files return literal maps and are parsed, never included. For example:

```php
// analysis/middleware-aliases.php
return ['role' => App\Http\Middleware\Role::class];
// analysis/middleware-groups.php
return ['staff' => ['role:admin']];
```

Use the existing alias/group source formats, including explicitly selected Kernel declarations for groups. Empty complete map files are valid when the application uses only direct class names. Include middleware class sources in Mago's indexed sources/includes.

Setting `middleware-native-dispatch` asserts that the selected maps are the effective complete maps for these calls, native Laravel group/alias and colon/comma resolution applies, and the container returns the named classes unchanged. It also asserts native Pipeline dispatch through `handle($request, $next, ...$parameters)`, without custom methods, runtime replacement, callable alternatives, or exclusion/filtering that would prevent the selected entries from being dispatched. If these conditions cannot be guaranteed, leave this option unset. This warning expresses a contradiction with the declared contract; it does not claim that a route will actually execute.

For `handle($request, $next, string $role)`, `middleware('role')` warns; `middleware('role:admin')` and `middleware('role:')` do not. For direct references, an empty suffix is one empty string argument and `:0` is one string argument. Native group expansion drops empty and `0` suffixes when rebuilding member strings, so those group members have no middleware arguments and can warn. Extra strings are accepted by PHP userland calls and are not diagnosed. Optional and variadic parameters are respected. Public inherited handle declarations are supported.

`laramago-incompatible-middleware-parameter` also checks native parameter declarations under the same contract. Pipeline passes each colon/comma token as a string. A supplied string cannot satisfy `array`, `object`, `iterable`, a class/interface, an intersection, or a union composed entirely of these types and `null`. For example, `handle($request, $next, array $roles)` with `middleware('roles:admin')` warns. An optional `?array $roles = null` accepts omission but rejects an explicitly supplied empty string. Inherited declarations and variadic parameters are covered; a rejected variadic declaration produces one warning regardless of token count. PHPDoc does not replace a native declaration for this check.

Native Pipeline calls use PHP's weak scalar argument coercion. This check deliberately does not classify scalar conversion success: numeric strings, boolean inputs, and even uncertain non-numeric scalar conversions defer. A union containing `string`, a scalar, `mixed`, or `callable` is not rejected. In particular, `string|Stringable` accepts string tokens. A standalone `Stringable` declaration still requires an object and cannot accept such a token. Callable-string validity and user-defined extra arguments are not diagnosed.

The check preserves unknown cases: incomplete/malformed catalogs, dynamic or mixed arrays, custom Route implementations, modified native registration bodies, cyclic groups, unresolved classes/method sources, and invokable classes are skipped. Group expansion is limited to 32 levels and 256 entries. This does not check scalar coercion, callable dispatch, termination, or route execution reachability. Middleware can receive objects through other runtime dispatch paths; those paths are outside the asserted literal registration contract and are not analyzed here.
