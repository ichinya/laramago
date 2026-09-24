# Final controller call contracts

`extra.laramago.controller-call-contracts` enables two warnings during ordinary
`mago analyze`: missing required positional arguments and provably incompatible
native parameter types. The input is an independently established final method
call, after route bindings and dependency resolution. It is not a route source
inventory or a list of container registrations.

```json
{
  "extra": {
    "laramago": {
      "controller-call-contracts": {
        "final-positional-call-asserted": true,
        "entries": [
          {
            "controller": "App\\Http\\Controllers\\InvoiceController",
            "method": "show",
            "arguments": [
              {"class": "App\\Services\\InvoiceRepository"},
              "string"
            ]
          }
        ]
      }
    }
  }
}
```

Each entry asserts that the identified concrete controller method is actually
invoked with exactly this ordered argument list. The assertion covers the final
selected controller object, route defaults, explicit and implicit bindings,
container overrides, attributes, callbacks and any `callAction` or dispatcher
transformations. Describe values **after** all transformations, immediately before
the PHP method invocation. An intercepted call that never reaches the method does
not satisfy the contract. A class descriptor asserts the exact object class, not
an interface or a possible base type. Omit an entry if any of this is unknown or
varies between covered calls. No complete inventory of unrelated routes is needed.

Supported argument descriptors are `string`, `int`, `float`, `bool`, `array`,
`null` and `{"class": "Fully\\Qualified\\Class"}`. These are type descriptors,
not literal values. Do not include named argument keys or route placeholder names.
Class and method names are case insensitive; an optional leading namespace
separator is accepted. Unknown fields, malformed descriptors, duplicate
controller/method pairs, more than 256 entries or more than 64 arguments disable
the whole contract. The assertion must be exactly `true`; the check is off by
default.

`laramago-missing-controller-arguments` points to the method name when the final
list omits a required position. Optional defaults and variadics are respected,
including PHP's required-after-optional rule. Extra userland arguments are not
errors. `laramago-incompatible-controller-argument` points to the native parameter
and identifies the rejected argument position. Variadic arguments are checked
individually. A nullable type accepts null; every union arm must reject an argument
before a warning is emitted. Legacy implicitly nullable defaults also accept null.

The first subset covers directly declared public instance methods on concrete
classes, including `__invoke`. Inherited methods, abstract or incomplete controller
hierarchies, by-reference parameters, other magic methods and nonpublic/static
actions defer. Object subtype checks require complete known class hierarchies.
Unknown types, intersections, `self`/`parent`, callable validity, object iterability,
scalar coercions and Stringable conversions defer. The check uses native parameter
types; it neither treats narrower PHPDoc as a PHP runtime failure nor changes any
native or documented inferred type. Mago's existing diagnostics remain intact.

[Laravel controller injection](https://laravel.com/docs/12.x/controllers#dependency-injection-and-controllers)
and [PHP argument semantics](https://www.php.net/manual/en/functions.arguments.php)
explain why URL placeholder-name matching is insufficient. For example, a final
`[Repository, string]` list matches `show(Repository $service, string $differentName)`
regardless of the placeholder spelling. The same two positions in reverse order
cannot satisfy the Repository parameter.

The [controller route source export](controller-route-contract-candidates.md)
remains advisory and does not generate these assertions automatically. Native
dispatcher identity, effective route registration, model binding and DI discovery
remain separate work. This feature also works with an independently understood
custom dispatcher because the assertion describes the final PHP call, not that
dispatcher's intermediate inputs or implementation.

Validation uses the real Mago worker, checks enabled/disabled native diagnostic
parity, and covers missing/incompatible arguments, nullable/unions, defaults,
variadics, positional names, PHPDoc, injection attributes, uncertain hierarchies,
malformed assertions and source changes between runs. The synthetic project has
spaces in its path, a custom vendor directory, no `.env` or DB, and throwing
application autoload/bootstrap/source sentinels. Only source is analyzed; no
application code is run.
