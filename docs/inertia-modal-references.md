# Inertia UI modal page references

Laramago can check literal page names passed to `Inertia::modal()` and
`Inertia\ResponseFactory::modal()` when the installed `inertiaui/modal` package
has the verified provider and response implementation. Configure an explicitly
complete `inertia-pages` catalog and assert the provider is active before the
analyzed calls:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "inertia-pages": {
          "complete": true,
          "paths": ["resources/js/Pages"],
          "extensions": ["vue"]
        },
        "inertia-modal": {
          "provider-active": true,
          "macro-unmodified": true,
          "native-helper-active": true
        }
      }
    }
  }
}
```

`provider-active` asserts that `InertiaUI\Modal\ModalServiceProvider::boot()`
has registered the macro before these calls. `macro-unmodified` asserts that
no later registration, mixin, or flush changes `ResponseFactory`'s `modal`
macro. `native-helper-active` asserts that the core Inertia `inertia()` helper
has not been preempted or replaced. Source presence and Composer auto-discovery
metadata alone do not prove runtime boot order, so these assertions are required.

The analyzer checks the installed package's provider registration and `Modal`
constructor and `toResponse()` path against normalized SHA-256 snapshots of
the upstream 3.x source included in the focused fixture. It also verifies the
native Inertia facade/factory dispatch, global Inertia helper, absence of a
namespaced helper override, and Laravel Macroable implementation.
Changed source, absent packages, custom facades or factory bindings, missing
assertions, dynamic and unpacked page names, and incomplete page catalogs defer.
This adapter does not claim support for other modal packages or versions whose
source differs from the verified snapshot.

Run `php -d opcache.enable_cli=0 tests/inertia-modal-references.php` for the
real Mago positive and negative cases. The upstream `inertiaui/modal` source
fixtures are MIT licensed; see `tests/fixtures/analysis/inertiaui-modal-LICENSE.md`.
The copied core helper and Macroable fixtures carry their respective MIT
licenses alongside them.
