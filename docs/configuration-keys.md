# Complete configuration key contracts

Static configuration files do not prove the final runtime contents of Laravel's
configuration repository. Applications, packages, service providers and tests can
merge, replace or remove values. Enable missing-key diagnostics only when the
runtime key set stays equal to the conventional application configuration files:

```json
{
  "extra": {
    "laramago": {
      "configuration-keys": {
        "complete": true,
        "runtime-configuration-unchanged": true
      }
    }
  }
}
```

`complete: true` asserts that every runtime configuration key is represented by
the static application configuration. `runtime-configuration-unchanged: true`
asserts that no package, provider, application bootstrap or test adds, replaces,
forgets or otherwise changes that key set. Both assertions are required. Omit the
contract when either assertion cannot be maintained.

The containing array must also have a source-complete literal string-key catalog.
Dynamic values, including `env()` calls, do not hide an existing key. Dynamic
parents, unknown namespaces, conditional configuration files, unpacked entries,
dynamic or numeric keys, and absent top-level namespaces defer because their
absence is not proven. This source-completeness check is independent from the two
explicit runtime assertions.

For source consumers, `ConfigurationIndex::stringKeyConfidence()` returns
`KnownPositive` for a literal key, `CompleteAbsent` only for a missing key in a
source-complete array, and `Unknown` for partial, unreadable or unavailable
catalogs. These states describe source evidence; `CompleteAbsent` alone does not
authorize a runtime missing-key diagnostic.

An absent literal produces `laramago-missing-configuration-key` for Laravel's
installed global `config()` helper and native
`Illuminate\Support\Facades\Config::get()`. Native facade `getMany()` calls also
check literal names in a closed array literal: unkeyed and supported numeric-keyed
entries use their string values as names, while non-numeric string keys are names
with their values serving as defaults. Native `Config::string()`, `integer()`,
`float()`, `boolean()`, `array()` and `collection()` calls check literal keys while
retaining Laravel's declared result types and exceptions. Typed checks require the
installed repository's standard typed method, `get()` forwarding body, and, for
`collection()`, the standard `array()` chain. Helper checks require Laravel's
standard parameter names and forwarding body through the native global `app()`
helper to the `config` service. Facade checks require the unmodified `config`
accessor and the corresponding native `Illuminate\Config\Repository` dispatch. A cataloged
custom `config` binding, custom helper or `app()` function, changed helper body,
custom facade accessor, dynamic key, argument unpacking or first-class callable
disables the diagnostic. `getMany()` also defers dynamic arrays, unpacked or
referenced entries, computed or duplicate array keys and negative integer-coercible
keys because array overwrite and append behavior can change the effective entries.
Positional arguments and named `key:` or `keys:` arguments are supported where Laravel declares them.

When a missing literal has exactly one nearby key in the same complete source
array, the diagnostic offers a replacement for that PHP string literal. Mago
marks the edit `potentiallyunsafe` because the intended key is still a guess.
Case-only differences, equally close names, dynamic or incomplete catalogs, and
unsupported source names receive no edit. The replacement is a quoted PHP
literal that preserves special characters in the proposed key. Mago's default
`analyze --fix` applies only safe edits, so this suggestion requires deliberate
`--potentially-unsafe` opt-in to apply.

Native `#[Illuminate\Container\Attributes\Config('app.key')]` injection attributes
also check literal keys under this contract. The constructor, resolver, container
contract and configuration repository must retain their native declarations.
Cataloged contextual attribute handlers, service replacements and dynamic keys defer.

Repository instances, `Config::get()` array forwarding, `push()`,
`prepend()` and other configuration operations remain outside this contract.
Existing native signatures, PHPDoc, argument diagnostics and return types retain
priority. Laramago reads Composer JSON, PHP syntax and installed framework metadata
only; it does not bootstrap Laravel or execute helpers, configuration files or
application code. A stale or incorrect explicit contract can cause false warnings.
