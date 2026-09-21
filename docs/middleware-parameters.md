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

The check preserves unknown cases: incomplete/malformed catalogs, dynamic or mixed arrays, custom Route implementations, modified native registration bodies, cyclic groups, unresolved classes/method sources, and invokable classes are skipped. Group expansion is limited to 32 levels and 256 entries. This does not check middleware parameter types, scalar coercion, callable dispatch, termination, or route execution reachability.
