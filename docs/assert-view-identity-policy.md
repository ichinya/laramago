# Permitted `assertViewIs` identity policy

Laravel's native `TestResponse::assertViewIs($value)` checks that the response
contains a view and compares `$value` with the stored view object's `name()`.
It does not ask the view finder to resolve `$value`. A valid stored identity can
therefore be an ordinary dot name, a file-shaped name, an empty string or an
application-defined value that is absent from a complete finder catalog.

Applications that want a narrower convention for test assertions may opt into
an independent permitted-identity policy in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "assert-view-identity-policy": {
        "enabled": true,
        "identities": ["dashboard", "external/custom.php"]
      }
    }
  }
}
```

With this policy enabled,
`laramago-assert-view-identity-outside-policy` advises when an exact string
literal passed to native `Illuminate\Testing\TestResponse::assertViewIs()` is
absent from `identities`. This means only that the assertion reference is
outside the application's configured permitted list. It does not claim that a
view file is missing, that the finder cannot resolve the identity, or that the
runtime assertion will fail. Listed values are exact and case-sensitive;
arbitrary and file-shaped identities are accepted like any other string.

The policy activates only for an actual boolean `enabled: true` and a list of
strings. Missing, disabled or malformed policy data produces no advice. The
native `assertViewIs`, `ensureResponseHasView` and `responseHasView` method
bodies are checked against Laravel framework commit `7c75fbf`. Changed native
contracts, subclasses, custom response classes, dynamic expressions,
concatenations, first-class callables and unpacked arguments defer to Mago.
Native PHPDoc and argument diagnostics remain unchanged.
