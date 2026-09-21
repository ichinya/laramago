# Route view declaration references

`RouteViewReferenceExport::export($root, $files)` extracts literal view names
from native `Illuminate\Support\Facades\Route::view()` calls in an explicit list
of project-relative PHP files. It supports lexical facade aliases and positional
or named `view` arguments. Dynamic, unpacked, and missing view arguments are
reported as uncertainties. Custom facades and other methods remain outside the
contract.

The export is an opt-in declaration policy input. A consumer may compare its
`references` with a separately asserted permitted view-name catalog and require
every selected literal to be declared there. Such a mismatch means that the
literal violates that source policy; it does not prove that Laravel will try to
render a missing view.

Each reference includes the original half-open literal byte span, source line,
and SHA-256 hash. Its `confidence` is `declaration-policy-reference`, and
`runtimeLookup` is always `unresolved`. The scope is non-exhaustive and states
`runtimeLookupValidated: false`. Parse and read failures are retained without
source fragments. The exporter never discovers route files, loads a project
autoload file, executes PHP, boots Laravel, or reads environment values.

Laravel's native router stores the view and related response arguments as route
defaults when `Router::view()` registers a `ViewController` route. Those values
remain mutable. URI or domain parameters, explicit defaults, route binding,
middleware, and later parameter mutation can replace the registered `view`
value before `ViewController` calls the response factory. Consequently, even a
complete view-finder catalog cannot turn this source reference into proof of a
mandatory runtime lookup.

The standalone class can be used directly until the shared metadata CLI exposes
the corresponding source kind:

```php
$metadata = (new RouteViewReferenceExport)->export($projectRoot, [
    'routes/web.php',
    'routes/api.php',
]);
```
