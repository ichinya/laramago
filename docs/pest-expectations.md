# Static Pest expectation declarations

`PestExpectationCatalog` reads explicitly selected active PHP files without loading
Pest or the application. Opt in with source files in asserted execution order:

```json
{
  "extra": {
    "laramago": {
      "pest-expectations": {
        "sources": ["tests/Pest.php"]
      }
    }
  }
}
```

`declarations()` returns ordered records with the literal name, closure AST,
canonical source path, and line. A null result means the configuration or a
selected source could not be trusted. An empty list means no supported direct
declaration was found in those selected files; it does not prove absence from
Pest's runtime registry. The catalog recognizes direct top-level
`expect()->extend('name', function (...) { ... })` and arrow-closure forms,
including named `name:` and `extend:` arguments. Pest's `expect($value)` form
is also accepted. Conditions, helper bodies, dynamic names or closure values,
imports that shadow `expect`, and namespaced files remain outside the subset.

This is positive source metadata, not an effective registry or a Mago method
signature. It does not infer calls on `Expectation` or its `not` proxy, check
extension bodies, or validate that the selected files execute at runtime.
Native Pest methods, PHPDoc, and application contracts retain priority.

The syntax follows Pest 5.x source commit
[`8b6607e`](https://github.com/pestphp/pest/tree/8b6607e344b29b282a73fccba1363954bfede68c):
[`expect()`](https://github.com/pestphp/pest/blob/8b6607e344b29b282a73fccba1363954bfede68c/src/Functions.php),
[`Extendable::extend()`](https://github.com/pestphp/pest/blob/8b6607e344b29b282a73fccba1363954bfede68c/src/Concerns/Extendable.php),
and [`Expectation::__call()`](https://github.com/pestphp/pest/blob/8b6607e344b29b282a73fccba1363954bfede68c/src/Expectation.php).
