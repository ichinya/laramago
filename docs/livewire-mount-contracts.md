# Explicit Livewire mount declaration metadata

`LivewireMountContractCatalog` reads selected component classes as PHP syntax.
It is a positive metadata API for effective names in `livewire-components`, not
a runtime simulation of mounting. Select each class source explicitly:

```json
{
  "extra": {
    "laramago": {
      "livewire-components": {
        "version": 3,
        "files": ["app/Providers/livewire.php"]
      },
      "livewire-mount-contracts": {
        "version": 3,
        "components": [
          {"name": "posts.show", "file": "app/Livewire/ShowPost.php"}
        ]
      }
    }
  }
}
```

Both version values must agree and be `3` or `4`. Each selected name must have
an effective explicit registration with a class target. Its source file must
declare that exact, concrete class directly extending `Livewire\Component`.
Classes with traits or indirect ancestry stay unknown because those sources
could add hooks or properties. An invalid selection makes `components()` null.
An unselected name makes `component(name)` null; no negative runtime conclusion
follows from that result.

For each selected class, `component(name)` returns the class name, absolute
source file, class line, its own `mount()` declaration if present, and its own
direct, public, nonstatic property declarations. Each mount parameter has a
name, native PHP type text, `hasDefault`, `variadic`, and source line. A null
`mount` means this selected direct class has no declared mount method; it does
not establish a caller argument requirement. `hasDefault` describes source
syntax only. Nullable, union, intersection, and resolved class names are
retained. Contextual `self` and `parent` types remain contextual syntax.
PHPDoc, promoted or inherited properties, trait members, and custom resolvers
are not converted into native declarations here.

Livewire 3 and 4 pass mount data through lifecycle hooks and container method
resolution. Page components also receive route parameters and route-bound
public properties. Livewire 4 separates component parameters from HTML
attributes before invoking mount. For these reasons, the catalog does not
diagnose omitted or extra arguments, assert that PHP-required parameters must
be supplied by a caller, or override native Mago and PHPDoc contracts.
Application classes are never loaded or executed.

The boundary follows official [Livewire 3 lifecycle hooks](https://github.com/livewire/livewire/blob/3.x/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php),
[Livewire 4 lifecycle hooks](https://github.com/livewire/livewire/blob/4.x/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php),
[Livewire 3 route binding](https://github.com/livewire/livewire/blob/3.x/src/Drawer/ImplicitRouteBinding.php),
[Livewire 4 route binding](https://github.com/livewire/livewire/blob/4.x/src/Drawer/ImplicitRouteBinding.php),
and [Livewire 4 parameter splitting](https://github.com/livewire/livewire/blob/4.x/src/Mechanisms/HandleComponents/HandleComponents.php).
