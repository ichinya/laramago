# Controller route contract candidates

`ControllerRouteContractExport::export($root, $files)` links a bounded literal
route action to a unique public controller method declared in the same explicit
source selection. This source-only metadata is intended for navigation, review,
and a future consumer with a separate complete runtime contract. It does not
report controller dispatch errors.

The exporter accepts direct top-level or namespace-level `Route::get`, `post`,
`put`, `patch`, `delete`, `options`, and `any` registrations. The route facade
must resolve to `Illuminate\Support\Facades\Route`, the URI must be literal, and
the action must be one of these exact forms:

```php
Route::get('/reports/{report}', [ReportController::class, 'show']);
Route::post('/reports', ReportController::class);
Route::put('/reports/{report}', '\App\Http\Controllers\ReportController@update');
```

The linked method must be a unique public declaration in one of the selected
files. Inherited methods, controller groups, resource expansion, closures,
conditions, custom registrars, relative string actions, and dynamic actions are
outside this partial subset. Missing or ambiguous selected declarations are
counted in `selection`; they do not produce class or method diagnostics because
the analyzer's controller action hook already owns those checks.

Each contract retains route, action, method, parameter, native-type, and literal
URI/domain source locations. Parameter entries include declaration order,
default/reference/variadic flags, an exact native type token, an optional single
named-class dependency candidate, and lexical URI/domain placeholder matches.
These fields deliberately remain independent. A scalar placeholder name mismatch
is valid because Laravel's controller dispatcher passes ordinary route values by
position. A matching name is relevant to implicit model or backed-enum binding,
but does not prove that binding occurs. A named class is a dependency candidate;
its absence from a static binding list does not prove container failure.

`runtimeDispatchValidated` and `scope.exhaustive` are always false. Effective
domain and URI parameters can differ after route groups, defaults, explicit or
implicit binding, middleware, and later mutation. The runtime container can also
resolve dependencies not mentioned in selected source. Consumers must retain
these unknowns and must not turn this output into missing-parameter, type, name,
or container-binding diagnostics without a separate complete effective-route and
DI contract.

The exporter reads only explicitly supplied project-relative PHP files and never
loads the project's autoloader or executes source. It accepts at most 256 files,
1 MiB per file, 8 MiB total, 10,000 linked contracts, and 20,000 parameters.
Errors and reached limits remain visible in the result. Source locations are
half-open byte ranges and include the SHA-256 hash of the parsed file snapshot.
