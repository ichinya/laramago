# Contextual injection contracts

An optional real Mago check validates the final object class explicitly asserted
for one consumer constructor parameter. It does not infer Laravel's build stack,
execute a binding closure, or assign a global type to an abstract service.

```json
{
  "extra": {
    "laramago": {
      "contextual-injection-contracts": {
        "effective-resolution-asserted": true,
        "entries": [
          {
            "consumer": "App\\Http\\Controllers\\InvoiceController",
            "parameter": "repository",
            "concrete": "App\\Repositories\\SqlInvoiceRepository"
          }
        ]
      }
    }
  }
}
```

The assertion must be independently established: every container construction of
the named consumer covered by this analysis injects an object of the declared
concrete class into that parameter. It includes contextual binding precedence,
attribute handlers, callbacks, replacements, and per-call overrides. A registration
such as `when(...)->needs(...)->give(...)` alone does not establish this contract.
Omit the entry when final resolution varies or is unknown. Ordinary direct PHP
constructor calls remain subject to Mago's native argument checks.

`laramago-incompatible-contextual-injection` warns at the native parameter when
the asserted class has a complete known hierarchy and cannot satisfy its declared
object type. Compatible subtypes pass. Matching consumer names is case insensitive;
parameter names are case sensitive and omit `$`. Duplicate entries for the same
consumer and parameter defer even when their classes agree. At most 1,024 entries
are accepted. The check is disabled unless the assertion is exactly `true`.

This first subset checks directly declared public nonabstract constructors and
plain named object parameter types. Unknown classes or hierarchies, inherited
constructors, scalar, nullable, union/intersection, variadic and by-reference
parameters defer. The contract can describe an attribute's final injection result,
but the attribute is never executed or assumed to select a particular handler.
No return type provider or global binding map is changed. Source is taken from the
actual Mago analyzed snapshot; no application autoload, `.env`, bootstrap or DB is
needed. Assertions must be kept current as application wiring changes.

Validation: `php tests/contextual-injection-contracts.php` runs the genuine Mago
worker in a project path containing spaces. It covers incompatible/compatible
objects, unknown hierarchies, duplicate and missing assertions, unsupported
parameter shapes, attribute dispatch assertions, and retained native diagnostics.
The fixture throws if application source is executed.
