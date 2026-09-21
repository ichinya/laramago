# Route parameter metadata

Run `laramago-metadata --kind route-parameters --source routes/web.php`.
This mode does not support watch. Its `optional` field classifies an individual
URI placeholder's syntax; it does not prove that an actual URL call can omit the
parameter, including when domain and URI placeholders share a name.

`RouteParameterMetadataExport` reads an explicit list of project-relative PHP route files and
exports source-only candidates for a bounded set of named `Route` facade declarations. It never
loads the application's Composer autoloader, boots Laravel, reads `.env`, or executes route files.

The exporter supports direct literal `get`, `post`, `put`, `patch`, `delete`, `options`, and `any`
registrations. Literal `name`, `domain`, `prefix`, and `defaults` chains are retained. Literal nested
groups may contribute `as`/`name`, `domain`, and `prefix` values; common metadata-neutral middleware,
constraint, controller, namespace, and scoped-binding attributes do not prevent extraction. Dynamic
URI, name, domain, or prefix values and unsupported fluent mutations are omitted. The scope reports
`declarationCoverage: bounded-positive-only`, so absence from the export is never evidence that a
runtime route is missing.

Each candidate includes:

- its composed name, normalized URI and normalized domain;
- parameters in Laravel's domain-before-URI order;
- the source of each parameter, binding field, and URL-default lookup key;
- both the literal `?` marker and whether this occurrence matches Laravel's URI optional-parameter syntax;
- ordered route declaration-default keys, value kinds, and source locations without exporting the
  default values;
- the registration method/domain/URI identity, marked with `survival: unresolved`;
- original literal tokens, the final route-name span, source line, and SHA-256 content hash.

Laravel only includes optional placeholders from the URI in its URL-generator optional map. A
domain placeholder such as `{tenant?}` therefore has `declaredOptional: true` but `optional: false`.
Domain placeholders remain part of URL generation when a caller requests a relative URL. Binding
syntax such as `{post:slug}` is normalized to `{post}` and produces `urlDefaultKey: post:slug`, which
matches the native generator's default lookup.

`Route::defaults('locale', 'en')` changes the route's inbound declaration defaults. It does not fill
`{locale}` during URL generation. The export consequently labels these entries
`declarationDefaults` and does not expose them as URL defaults. Effective URL-generator defaults are
mutable state owned by `UrlGenerator::defaults()` and require a separate explicit contract.

The candidate identity is also not an active-route catalog. Later registrations can replace a URI
identity, route names can collide, route objects can be mutated, and external or conditional files
can change the final collection. Consumers must independently prove selected-source completeness,
group expansion, final surviving routes, effective URL defaults, and native generator ownership
before using this metadata for negative diagnostics.

The class is directly available through the package autoloader:

```php
use Ichinya\Laramago\Metadata\RouteParameterMetadataExport;

$metadata = (new RouteParameterMetadataExport)->export(
    root: '/project',
    files: ['routes/web.php', 'routes/api.php'],
);
```

The CLI uses the same exporter with its validated project root and explicit source list.
Selected-source watch is not enabled for this mode.
