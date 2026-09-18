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
use App\Services\SystemClock;

app()->singleton(Clock::class, SystemClock::class);
app()->alias(Clock::class, 'clock');
```

`bind`, `singleton` and `alias` are supported on proven native `app()` or
`Illuminate\Container\Container::getInstance()` receivers. Implementation classes
use `Implementation::class`. Paths must be relative files below `app/` or
`bootstrap/`, without parent-directory traversal. Ordinary service providers are
not automatically scanned.

Literal `app`, `resolve` and native container `make` calls can then retain the
known implementation. Literal facade accessors use the same catalog for root
types and public method forwarding. Class/interface keys must be compatible with
the final concrete implementation. Native declarations, explicit PHPDoc and
custom dispatch retain priority.

Core service overrides also disable related standard-service refinements:
authentication for `auth` or its factory contract, validated fields for `validator`
or its factory contract, and configuration/default-locale reads for `config`.
An uncertain opted-in catalog triggers the same conservative behavior.

Alias cycles, conflicting registrations, conditional registration, closures,
generic or abstract implementations and unknown mutations remain unresolved.
Contextual bindings, instances, extenders and registration-order inference are
outside this subset. Invalid or mutation-bearing catalogs disable inference
rather than asserting a partially guessed runtime container state.

`tests/container-bindings.php` checks catalog resolution, native/explicit-contract
precedence and conservative failures through the real worker. Catalogs, providers,
constructors and application bootstrap are never executed by this integration.

## Literal factory closures

Opt-in `binding-files` catalogs also accept parameterless arrow functions and
closures whose sole return expression is `new Concrete`, with an explicit native
return type naming exactly that concrete class. Imported names are resolved
syntactically. The factory result supports `app`, `resolve`, native container
`make`, alias chains, facade roots, and public facade forwarding. A direct `new`
result bypasses any separate binding for its concrete class.

Factory inference does not execute application PHP. It defers closures with
parameters, captures, reference returns, attributes, multiple statements,
conditional or dynamic expressions, constructor arguments, anonymous classes,
missing or broader return declarations, unresolved/abstract/generic classes,
conditional registrations, and duplicate registrations. Existing catalog mutation
safeguards and native/PHPDoc priority remain in force. This is not support for
arbitrary runtime factories or service-provider activation.
