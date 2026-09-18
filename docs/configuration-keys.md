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

An absent literal produces `laramago-missing-configuration-key` for Laravel's
installed global `config()` helper and native
`Illuminate\Support\Facades\Config::get()`. Helper checks require Laravel's
standard parameter names and forwarding body through the native global `app()`
helper to the `config` service. Facade checks require the unmodified `config`
accessor and native `Illuminate\Config\Repository::get()` dispatch. A cataloged
custom `config` binding, custom helper or `app()` function, changed helper body,
custom facade accessor, dynamic key, argument unpacking or first-class callable
disables the diagnostic. Positional and `key:` arguments are supported.

Repository instances, `getMany()`, typed getters, injection attributes, `push()`,
`prepend()` and other configuration operations remain outside this contract.
Existing native signatures, PHPDoc, argument diagnostics and return types retain
priority. Laramago reads Composer JSON, PHP syntax and installed framework metadata
only; it does not bootstrap Laravel or execute helpers, configuration files or
application code. A stale or incorrect explicit contract can cause false warnings.
