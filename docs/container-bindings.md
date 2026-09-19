# Static container binding catalogs

Applications may opt into syntax-only binding inference by listing catalog files:

```json
{
    "extra": {
        "laramago": {
            "binding-files": ["bootstrap/bindings.php"]
        }
    }
}
```

This is an explicit application contract: the listed registrations are active,
complete for the inferred services, and are not changed by other runtime bindings
or aliases. Laramago does not load or activate the files. The application remains
responsible for registering those bindings during its own normal startup.

Catalogs contain unconditional top-level native container calls, for example:

```php
<?php

use App\Contracts\Clock;
use App\Contracts\RequestClock;
use App\Services\SystemClock;
use App\Services\SystemRequestClock;

app()->singleton(Clock::class, SystemClock::class);
app()->scoped(RequestClock::class, SystemRequestClock::class);
app()->alias(Clock::class, 'clock');
```

`bind`, `singleton`, `scoped` and `alias` are supported on proven native `app()` or
`Illuminate\Container\Container::getInstance()` receivers. Implementation classes
use `Implementation::class`. Paths must be relative files below `app/` or
`bootstrap/`, without parent-directory traversal. Ordinary service providers are
not automatically scanned.

Literal `app`, `resolve` and native container `make` calls can then retain the
known implementation. Literal facade accessors use the same catalog for root
types and public method forwarding. Class/interface keys must be compatible with
the final concrete implementation. Native declarations, explicit PHPDoc and
custom dispatch retain priority.

Alias chains preserve exact container keys, including letter case and leading
backslashes in literal strings. Unknown terminals and conditional or conflicting
registrations anywhere along a chain remain unresolved. Duplicate aliases and
binding/alias overlaps do not assume which registration is effective.

Core service overrides also disable related standard-service refinements:
authentication for `auth` or its factory contract, validated fields for `validator`
or its factory contract, and configuration/default-locale reads for `config`.
An uncertain opted-in catalog triggers the same conservative behavior.

Alias cycles, conflicting registrations, conditional registration methods such as
`bindIf`, `singletonIf` and `scopedIf`, closures,
generic or abstract implementations and unknown mutations remain unresolved.
Contextual bindings, instances, extenders and registration-order inference are
outside this subset. Invalid or mutation-bearing catalogs disable inference
rather than asserting a partially guessed runtime container state.

`tests/container-bindings.php` checks catalog resolution, native/explicit-contract
precedence and conservative failures through the real worker. Catalogs, providers,
constructors and application bootstrap are never executed by this integration.

## Literal factory closures

Opt-in `binding-files` catalogs also accept arrow functions and closures whose
sole return expression is `new Concrete`, with an explicit native return type
naming exactly that concrete class. They may declare zero, one or both untyped
required arguments supplied by Laravel's container factory contract. Imported
names are resolved syntactically. The factory result supports `app`, `resolve`,
native container `make`, alias chains, facade roots, and public facade forwarding.
A direct `new` result bypasses any separate binding for its concrete class.

Factory inference does not execute application PHP. It defers closures with
optional, typed, variadic, reference, attributed or more than two parameters,
captures, reference returns, attributes, multiple statements, conditional or
dynamic expressions, constructor arguments, anonymous classes, missing or broader
return declarations, unresolved/abstract/generic classes, conditional
registrations, and duplicate registrations. Existing catalog mutation safeguards
and native/PHPDoc priority remain in force. This is not support for arbitrary
runtime factories or service-provider activation.

## Direct self-alias registrations

`laramago-container-self-alias` warns when `(new Illuminate\Container\Container)->alias(...)`
receives two identical literal string keys. Native Laravel throws `LogicException`
before changing state, including for empty strings and `"0"`. Named arguments are
matched by their exact parameter names; key case and leading backslashes are preserved.

This check requires the audited native `alias` declaration and a concrete exact
container without a constructor or class PHPDoc overrides. Subclasses, saved
instances, helper receivers, dynamic values, unpacking and modified native bodies
remain outside this check. PHP's invalid-call and instantiation diagnostics retain
priority. No application or catalog file executes.

Longer alias cycles continue to prevent type refinement. Diagnosing their effective
runtime graph requires registration-order, receiver and source-provenance proof;
this direct self-alias check does not claim to validate that graph.
