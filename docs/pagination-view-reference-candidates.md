# Pagination view reference candidates

`PaginationViewReferenceExport::export($root, $files)` extracts bounded view-name
candidates from an explicit list of project-relative PHP files. It recognizes
truthy literal first arguments, including named `view` arguments, on `links()`
and `render()` method calls. It also recognizes literal values assigned by exact
native `Illuminate\Pagination\Paginator::defaultView()` and
`defaultSimpleView()` calls.

Instance method candidates do not prove that their receiver is a native Laravel
paginator. A consumer must independently establish receiver identity before
applying an optional permitted-name policy. Even then, the metadata does not
prove view lookup: Laravel obtains its view factory through an independently
mutable static resolver. A complete view-finder catalog alone does not establish
that resolver's identity.

Native paginator rendering uses PHP's falsey fallback operator. Therefore an
explicit `''`, `'0'`, `null`, `false`, numeric zero, or empty-array argument is
reported as `falsey-view-uses-runtime-default` instead of a direct reference.
An omitted, unpacked, or otherwise unavailable argument is also uncertain.
Literal default declarations remain candidates even when their string is empty
or `'0'`: after selection, Laravel passes the stored default directly to the
resolved factory.

References retain the original half-open literal byte span, source line, and
SHA-256 content hash. The scope is non-exhaustive and explicitly reports
`paginatorReceiverValidated: false`, `viewFactoryResolverValidated: false`, and
`runtimeLookupValidated: false`. The exporter never discovers files, loads the
project Composer autoloader, executes project PHP, boots Laravel, reads the
environment, or connects to a database.

The standalone class can be used directly until the shared metadata CLI exposes
the corresponding source kind:

```php
$metadata = (new PaginationViewReferenceExport)->export($projectRoot, [
    'app/Providers/AppServiceProvider.php',
    'app/Http/Controllers/ReportController.php',
]);
```
