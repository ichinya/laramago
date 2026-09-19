# Static middleware alias metadata

`MiddlewareAliasCatalog` reads explicitly selected application sources without
bootstrapping Laravel, executing PHP, loading application classes or reading the
environment. It supplies reusable alias-to-class metadata; it does not add route
diagnostics, resolve middleware groups, validate classes or choose `handle` versus
`__invoke` methods.

Configure Composer metadata:

```json
{
  "extra": {
    "laramago": {
      "middleware-aliases": {
        "files": ["bootstrap/app.php"],
        "complete": false
      }
    }
  }
}
```

Selecting `files` asserts that the selected declarations are active, effective
registrations in the analyzed application, with no uncataloged changes to their
targets. This is an
application contract, not a claim that source analysis proves runtime activation.

The supported existing Laravel configuration form is a direct top-level return
of an `Illuminate\Foundation\Application::configure(...)` method chain with
exactly one `withMiddleware(...)` call. Its argument must be a closure with one
parameter typed as `Illuminate\Foundation\Configuration\Middleware`, without
captured variables. Imports and named `callback`/`aliases` arguments are supported:

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\Authenticate;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['auth' => Authenticate::class]);
    })
    ->create();
```

The callback body may contain only direct `alias(...)` calls on that parameter.
Each call replaces the earlier custom aliases, matching Laravel's implementation.
Other configuration calls, conditions, assignments, dynamic arrays, captures and
unknown receivers make the entire catalog unknown. Other builder callbacks are
not inspected; the activation assertion excludes their changing these aliases.

Alternatively select a reusable PHP file whose only executable statement is a
literal array return, for example `bootstrap/middleware-aliases.php`:

```php
use App\Http\Middleware\Authenticate;

return ['auth' => Authenticate::class];
```

Applications can already pass such a file to `$middleware->alias(require ...)`;
the analyzer reads the selected map directly and does not execute that `require`.
Sources must be project-relative PHP paths, with no parent
traversal; resolved paths must remain inside the project. Multiple source maps overlay earlier maps in listed order. That order
asserts the effective combined map; it does not model repeated runtime `alias()`
calls, which replace the custom map. Imports and `declare(strict_types=1)` statements are
accepted. Class targets must be literal class strings or resolved `::class`
expressions. Closures, parameterized targets, numeric alias names and aliases
containing colons or whitespace are unsupported. Duplicate literal keys use the
last value. No class existence or container-binding assertion is implied.

Laravel's default aliases are not automatically added: defaults include runtime
choices, and packages or the kernel may replace them. `complete: true` separately
asserts that **all** effective aliases, including defaults and packages, are in
the selected maps. A custom `alias()` array alone normally cannot make that
assertion. Malformed settings, unreadable or unsupported sources invalidate the
entire catalog, even if `complete` was requested. An explicitly empty complete
catalog is valid; completeness defaults to false and must be a boolean.

The small consumer interface is:

- `aliases(): ?array` returns the exact case-sensitive alias-to-class map, or null
  for an absent or unknown catalog.
- `target(string): ?string` returns a known normalized class name or null.
- `contains(string): ?bool` returns true for known aliases, false only for absent
  names in a valid complete catalog, and null otherwise.
- `isComplete(): bool` reports the effective completeness assertion.

Lookups are exact: `auth:web` is not split into `auth` and a parameter. A false
alias lookup does not establish invalid middleware: a consumer must separately
account for groups, direct classes, bindings and custom dispatch. Legacy Kernel
declarations, group expansion, middleware reference diagnostics and parameter
contracts are separate features.

Literal target strings preserve their leading backslash and case because container binding keys can depend on the exact spelling.
