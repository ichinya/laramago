# Literal public asset references

`asset()` and `secure_asset()` generate URLs. Laramago can warn when their
literal local path is missing from an explicitly complete catalog of expected
public files. It does not claim that Laravel throws, or that an HTTP request
will return 404.

Enable the catalog in the application's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "public-assets": {
          "complete": true,
          "paths": ["public", "custom public"]
        }
      }
    }
  }
}
```

Each path is an existing project-contained public root. The declaration
asserts that these roots contain every locally expected static public file.
The warning applies to literal `asset()` and `secure_asset()` paths and to
calls on an exact native `Illuminate\Routing\UrlGenerator` receiver. One
leading slash is accepted. A present file, a case-only mismatch, an incomplete
catalog, a custom helper, changed native forwarding, or a configured custom
URL service does not produce a helper warning. Direct calls on an exact native
URL generator remain independently checkable when a helper or service changes.

Absolute and protocol-relative URLs, query strings, fragments, percent
encoding, dot segments, dynamic expressions, unpacked arguments and ambiguous
filesystem entries defer. Laravel may generate a CDN URL, serve a route or
dynamic response at the same path, or let a web server resolve a directory
index. Those behaviors are not proven by the file catalog. Configure this
contract only for local static file paths whose expected existence you can
assert. Mix/Vite manifests, CDN assets, routes and dynamically served paths
are outside this check. The index is read from files without application
bootstrap, URL generation, HTTP requests or database access.
